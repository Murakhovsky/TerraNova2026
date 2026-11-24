<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require (getenv('COS_TEST_AUTOLOAD')?:$root.'/vendor/autoload.php');
use Domains\Marketing\Domain\MarketingAttributionPolicy as Policy;
use Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingAttributionStore;
use Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingCampaignRepository;
use Domains\Marketing\Domain\MarketingDraftPolicy;
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',getenv('DB_HOST')?:'127.0.0.1',getenv('DB_PORT')?:'3306',getenv('DB_DATABASE')?:'cos'),getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$assert=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$org='mkt-m4-'.bin2hex(random_bytes(6));$other='mkt-m4-'.bin2hex(random_bytes(6));
$campaigns=new MysqlMarketingCampaignRepository($db);$store=new MysqlMarketingAttributionStore($db);
$campaign=$campaigns->createDraft($org,'42',(new MarketingDraftPolicy())->validate(['name'=>'M4 integration fixture','business_objective'=>'Native lead attribution']),'m4-campaign-1')['id'];
$lead=['lead_id'=>'42','created_at'=>'2026-01-01 10:00:00','utm_campaign'=>$campaign,'utm_source'=>'google','utm_medium'=>'organic'];
$e=Policy::evidence($lead,$campaign,null);
$h=hash('sha256','capture');
$r=$store->batch($org,'m4-capture-key',$h,fn()=>['result'=>$store->record($org,$campaign,$e)]);
$repeat=$store->batch($org,'m4-capture-key',$h,fn()=>throw new RuntimeException('Replay executed callback.'));
$assert($r===$repeat,'Capture receipt not immutable.');
$deny=false;try{$store->batch($org,'m4-capture-key',str_repeat('f',64),fn()=>[]);}catch(DomainException){$deny=true;}
$assert($deny,'Conflicting capture key accepted.');
$assert($store->record($org,$campaign,$e)['id']===$r['result']['id'],'Source record duplicated.');
$deny=false;try{$store->record($org,$campaign,Policy::evidence([...$lead,'utm_medium'=>'email'],$campaign,null));}catch(DomainException){$deny=true;}
$assert($deny,'Source attribution drift reallocated.');
$assert(count($store->list($org,$campaign,'2026-01-01','2026-01-01',50,0))===1
    &&$store->list($other,$campaign,'2026-01-01','2026-01-01',50,0)===[],'Tenant/window evidence scope incorrect.');
try{$store->batch($org,'m4-rollback-key',$h,function()use($store,$org,$campaign,$lead){
    $store->record($org,$campaign,Policy::evidence([...$lead,'lead_id'=>'43'],$campaign,null));
    throw new RuntimeException('synthetic rollback');
});}catch(RuntimeException $e){if($e->getMessage()!=='synthetic rollback')throw $e;}
$assert(count($store->list($org,$campaign,'2026-01-01','2026-01-01',50,0))===1,'Partial capture committed.');
echo "Marketing M4 native MySQL capture receipt, tenant, idempotency, drift and rollback PASS\n";

spl_autoload_register(static function(string $class)use($root):void{
    if(!str_starts_with($class,'App\\'))return;
    $path=$root.'/symfony/src/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($path))require $path;
});
$db->prepare('INSERT INTO cos_organizations (id,name,status) VALUES (?,?,"ACTIVE")')->execute([$org,'M4 native source fixture']);
$manifest=require $root.'/app/Domains/Marketing/module.php';
$catalog=new \Kernel\Module\ModuleCatalog([\Kernel\Module\ModuleDefinition::fromArray($manifest),
    \Kernel\Module\ModuleDefinition::fromArray(require $root.'/app/Domains/Sales/module.php'),
    \Kernel\Module\ModuleDefinition::fromArray(require $root.'/app/Domains/Finance/module.php')]);
$states=new class implements \Kernel\Module\Contract\ModuleStateRepositoryInterface{
    public function enabledOverride(string $org,string $moduleId):?bool{return true;}
    public function setEnabled(string $org,string $moduleId,bool $value):void{}
};
$modules=new \Kernel\Module\ActiveModuleResolver($catalog,$states);
$actor=new \Kernel\Tenant\Model\TenantContext(\Kernel\Shared\Domain\UserId::fromString('42'),\Kernel\Shared\Domain\OrganizationId::fromString($org),
    \Kernel\Identity\Model\OrganizationRole::fromString('manager'),[
        \Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::ACCESS),
        \Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::MANAGE)]);
$campaignService=new \Domains\Marketing\Application\Service\MarketingCampaignService($campaigns,new MarketingDraftPolicy());
$c2=$campaigns->createDraft($org,'42',(new MarketingDraftPolicy())->validate(['name'=>'M4 real Sales link','business_objective'=>'Native outcome analytics']),'m4-campaign-2')['id'];
$accounts=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidReadRepository($db);
$planning=new \Domains\Marketing\Application\Service\MarketingPlanningService(new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPlanningRepository($db),new \Domains\Marketing\Domain\MarketingPlanningPolicy());
$financeRepo=new \Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinanceBudgetRepository($db);
$financeRead=new \Domains\Finance\Application\Service\FinanceBudgetControlService($financeRepo,new \Domains\Finance\Domain\BudgetControlPolicy());
$plans=new \Domains\Marketing\Application\Service\MarketingPaidLaunchPlanService(
    new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidLaunchPlanStore($db),$campaignService,$planning,$accounts,
    new \App\Infrastructure\Marketing\FinanceFundingReadAdapter($financeRead,$modules),$modules);
$provider=new class implements \Domains\Marketing\Application\Contract\PaidMediaProviderInterface{
    public function inspectAccount(string $organizationId,string $providerAccountId,string $credentialRef):array{throw new DomainException('No external calls.');}
    public function hierarchy(string $organizationId,string $providerAccountId,string $credentialRef):array{throw new DomainException('No external calls.');}
    public function insights(string $organizationId,string $providerAccountId,string $credentialRef,string $from,string $to,string $grain):array{throw new DomainException('No external calls.');}
};
$paid=new \Domains\Marketing\Application\Service\MarketingPaidReadService($accounts,$provider);
$ops=new \Domains\Marketing\Application\Service\MarketingPaidOperationsReadService($plans,
    new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidOperationJournal($db),
    new \Domains\Marketing\Infrastructure\Meta\MetaPaidExecutionProfiles('{}',false),$paid);
$sales=new \App\Infrastructure\Marketing\SalesAttributionReadAdapter(new \Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesWorkspaceReadModel($db),$modules);
$settler=new \Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinancePaidSettlementRepository($db,new \Domains\Finance\Domain\FinancePaidSettlementPolicy());
$finance=new \App\Infrastructure\Marketing\FinanceAnalyticsReadAdapter($financeRepo,$settler,$modules);
$analytics=new \Domains\Marketing\Application\Service\MarketingAnalyticsService($campaignService,$plans,$ops,$paid,$store,$sales,$finance,$modules);
$db->prepare("INSERT INTO tn_leads (organization_id,full_name,status,utm_source,utm_medium,utm_campaign,created_at) VALUES (?,'Private fixture','qualified','google','organic',?,'2026-01-01 10:00:00')")->execute([$org,$c2]);
$id=(string)$db->lastInsertId();
$db->prepare("INSERT INTO tn_leads (organization_id,full_name,status,utm_source,utm_medium,utm_campaign,created_at) VALUES (?,'Unmatched fixture','new','google','organic',?,'2026-01-01 11:00:00')")->execute([$org,$campaign]);
$wrong=(string)$db->lastInsertId();
$db->prepare('INSERT INTO tn_people (organization_id,public_id,full_name) VALUES (?,?,?)')->execute([$org,'PN-'.bin2hex(random_bytes(5)),'Private fixture']);
$person=(int)$db->lastInsertId();$pipeline='p-'.bin2hex(random_bytes(10));$stage='s-'.bin2hex(random_bytes(10));
$db->prepare("INSERT INTO sales_pipelines (id,organization_id,code,name,status) VALUES (?,?,'M4FIXTURE','M4 fixture','ACTIVE')")->execute([$pipeline,$org]);
$db->prepare("INSERT INTO sales_pipeline_stages (id,organization_id,pipeline_id,code,name,is_terminal,is_won,probability_default) VALUES (?,?,?,'WON','Won',1,1,100)")->execute([$stage,$org,$pipeline]);
$db->prepare("INSERT INTO tn_client_cases (organization_id,public_id,person_id,type,title,status,lead_id,pipeline_id,stage_id,deal_value,currency,closed_at) VALUES (?,?,?,'buy','Private deal','closed',?,?,?,125.50,'EUR','2026-01-02 10:00:00')")->execute([$org,'CC-'.bin2hex(random_bytes(5)),$person,$id,$pipeline,$stage]);
$deal=(int)$db->lastInsertId();
$db->prepare('UPDATE tn_leads SET client_case_id=? WHERE organization_id=? AND id=?')->execute([$deal,$org,$id]);
$discovery=$analytics->discover($actor,$c2,null,'2026-01-01','2026-01-02',0);
$assert(array_column($discovery['matches'],'lead_id')===[$id]&&!$discovery['complete_coverage'],'Exact native lead discovery failed.');
$receipt=$analytics->capture($actor,$c2,null,[$id,$wrong],'m4-native-capture');
$statesByLead=array_column($receipt['results'],'state','lead_id');
$assert($statesByLead[$id]==='RECORDED'&&$statesByLead[$wrong]==='REJECTED','Native source tag check failed.');
$r=$analytics->report($actor,$c2,null,'2026-01-01','2026-01-02',null,null);
$assert($r['leads']===1&&$r['qualified_leads']===1&&$r['won_deals']===1&&$r['won_deal_value']===[['currency'=>'EUR','minor'=>12550]]
    &&$r['roas']===null&&!$r['recognized_revenue_confirmed'],'Native Sales outcome or currency semantics invalid.');
$assert(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'Private fixture'),'PII leaked into analytics report.');
$db->prepare("UPDATE tn_leads SET utm_medium='email' WHERE organization_id=? AND id=?")->execute([$org,$id]);
$r=$analytics->report($actor,$c2,null,'2026-01-01','2026-01-02',null,null);
$assert($r['leads']===0&&$r['source_drift_count']===1,'Revised native UTM not quarantined.');
echo "Marketing M4 native Sales lead, discovery, won deal, currency, PII and drift E2E PASS\n";
