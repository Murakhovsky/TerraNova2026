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
$lead=['lead_id'=>'999999999999999998','created_at'=>'2026-01-01 10:00:00','utm_campaign'=>$campaign,'utm_source'=>'google','utm_medium'=>'organic'];
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
    $store->record($org,$campaign,Policy::evidence([...$lead,'lead_id'=>'999999999999999997'],$campaign,null));
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
$db->prepare("INSERT INTO tn_client_cases (organization_id,public_id,person_id,type,title,status,inbound_request_id,pipeline_id,stage_id,deal_value,currency,closed_at) VALUES (?,?,?,'buy','Private deal','closed',?,?,?,125.50,'EUR','2026-01-02 10:00:00')")->execute([$org,'CC-'.bin2hex(random_bytes(5)),$person,$id,$pipeline,$stage]);
$deal=(int)$db->lastInsertId();
$db->prepare('UPDATE tn_leads SET client_case_id=? WHERE organization_id=? AND id=?')->execute([$deal,$org,$id]);
$assert($sales->lead($actor,$id)['deal']['deal_id']===(string)$deal,'Canonical inbound request backlink missing.');
$discovery=$analytics->discover($actor,$c2,null,'2026-01-01','2026-01-02',0);
$assert(array_column($discovery['matches'],'lead_id')===[$id]&&!$discovery['complete_coverage'],'Exact native lead discovery failed.');
$receipt=$analytics->capture($actor,$c2,null,[$id,$wrong],'m4-native-capture');
$statesByLead=array_column($receipt['results'],'state','lead_id');
$assert($statesByLead[$id]==='RECORDED'&&$statesByLead[$wrong]==='REJECTED','Native source tag check failed.');
$r=$analytics->report($actor,$c2,null,'2026-01-01','2026-01-02',null,null);
$assert($r['leads']===1&&$r['qualified_leads']===1&&$r['won_deals']===1&&$r['won_deal_value']===[['currency'=>'EUR','minor'=>12550]]
    &&$r['roas']===null&&!$r['recognized_revenue_confirmed'],'Native Sales outcome or currency semantics invalid.');
$assert(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'Private fixture'),'PII leaked into analytics report.');
$db->prepare("UPDATE tn_client_cases SET closed_at='2026-01-01 09:59:59' WHERE organization_id=? AND id=?")->execute([$org,$deal]);
$chronology=$analytics->report($actor,$c2,null,'2026-01-01','2026-01-02',null,null);
$assert($chronology['linked_deals']===1&&$chronology['won_deals']===0&&$chronology['won_deal_value']===[],'Pre-acquisition same-day deal counted as conversion.');
$db->prepare("UPDATE tn_client_cases SET closed_at='2026-01-02 10:00:00' WHERE organization_id=? AND id=?")->execute([$org,$deal]);
$db->prepare("UPDATE tn_leads SET utm_medium='email' WHERE organization_id=? AND id=?")->execute([$org,$id]);
$r=$analytics->report($actor,$c2,null,'2026-01-01','2026-01-02',null,null);
$assert($r['leads']===0&&$r['source_drift_count']===1,'Revised native UTM not quarantined.');
echo "Marketing M4 native Sales lead, discovery, won deal, currency, PII and drift E2E PASS\n";

$account=$accounts->registerAccount($org,'env://META_M4_FIXTURE',['ad_account_id'=>'act_'.random_int(1000000000,9999999999),'currency'=>'USD','timezone'=>'UTC','connection_status'=>'READ_ONLY','capability_scope'=>['ads_read']]);
$providerAccount=substr($account['ad_account_id'],4);
$mediaStore=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingMediaAnalyticsStore($db,$accounts);
$base=['account_id'=>$providerAccount,'campaign_id'=>'90001','date_start'=>'2026-01-01','date_stop'=>'2026-01-03','spend'=>'1.234567','impressions'=>'100','clicks'=>'5'];
$run=$mediaStore->commit($org,'42','m4-media-key',hash('sha256','media'),$account,'campaign','2026-01-01','2026-01-03',[$base]);
$assert(count($run['rows'])===1&&$run['rows'][0]['reported_spend_decimal']==='1.234567'&&$run['rows'][0]['source_revision']===1,'Native media receipt missing.');
$changed=$mediaStore->commit($org,'42','m4-media-key-2',hash('sha256','media2'),$account,'campaign','2026-01-01','2026-01-03',[[...$base,'spend'=>'2.345678']]);
$assert($changed['rows'][0]['source_revision']===2&&$mediaStore->get($org,$run['id'])['rows'][0]['reported_spend_decimal']==='1.234567'
    &&$mediaStore->get($other,$run['id'])===null,'Immutable batch or tenant fence failed.');
$empty=$mediaStore->commit($org,'42','m4-media-key-3',hash('sha256','media3'),$account,'campaign','2026-01-01','2026-01-03',[]);
$assert($empty['rows']===[]&&$mediaStore->latest($org,$account['id'],'campaign','2026-01-01','2026-01-03')['id']===$empty['id'],'Empty complete batch inherited old rows.');
$ad=$mediaStore->commit($org,'42','m4-media-ad-key',hash('sha256','media-ad'),$account,'ad','2026-01-01','2026-01-03',[[...$base,'ad_id'=>'90002']]);
$assert($ad['grain']==='ad'&&$ad['rows'][0]['external_id']==='90002','Native ad-grain receipt failed.');
echo "Marketing M4 immutable complete provider batch, revised/empty scope, ad grain and tenant MySQL PASS\n";

$commerceSource=new class implements \Domains\Marketing\Application\Contract\MarketingCommerceOrderReadPort, \Domains\Marketing\Application\Contract\MarketingCommerceLifecycleReadPort {
    public array $lifecycleFacts=[];
    public function lifecycle(\Kernel\Tenant\Model\TenantContext $actor,string $orderId,int $afterRevision,int $limit):?array{return $this->lifecycleFacts[$actor->organizationId()->value()][$orderId]??null;}
    public array $facts=[];public bool $available=true;
    public function assertAvailable(\Kernel\Tenant\Model\TenantContext $actor):void{
        if(!$this->available)throw new DomainException('Source unavailable.');
    }
    public function get(\Kernel\Tenant\Model\TenantContext $actor,string $orderId):?array{
        $this->assertAvailable($actor);return $this->facts[$actor->organizationId()->value()][$orderId]??null;
    }
};
$commerceStore=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingCommerceAttributionStore($db);
$commerce=new \Domains\Marketing\Application\Service\MarketingCommerceAttributionService($campaignService,$plans,$commerceSource,$commerceStore,$store,$modules);
$orderId=str_repeat('a',32);$shopId=str_repeat('b',32);$badId=str_repeat('c',32);
$fact=static fn(string $id,string $event,string $channel,string $currency,string $value,array $tags):array=>[
    'placement'=>['schema_version'=>'1.0.0','event_id'=>$event,'event_type'=>'sales.commerce.order.placed','organization_id'=>$org,
        'order_id'=>$id,'revision'=>1,'occurred_at'=>'2026-01-02T10:00:00.000000Z','channel'=>$channel,'currency'=>$currency,'order_total'=>$value,
        'financial_evidence_reference'=>null,'realized_revenue'=>null,'attribution_status'=>'unresolved',
        'provenance'=>$channel==='native'?'native_operator':'shopify_admin_read','attribution_observations'=>(object)$tags],'journey'=>null];
$tag=['utm_source'=>'google','utm_medium'=>'organic','utm_campaign'=>$c2];
$commerceSource->facts[$org][$orderId]=$fact($orderId,str_repeat('1',32),'native','EUR','125.50',$tag);
$commerceSource->facts[$org][$shopId]=$fact($shopId,str_repeat('2',32),'shopify','USD','20.00',[]);
$commerceSource->facts[$org][$badId]=$fact($badId,str_repeat('3',32),'native','EUR','25.00',[...$tag,'utm_campaign'=>$campaign]);
$assert($commerce->inspect($actor,$c2,null,$shopId)['state']==='UNRESOLVED','Absent Shopify journey became conversion.');
$commerceSource->facts[$org][$shopId]['journey']=['state'=>'observed','evidence_hash'=>str_repeat('f',64),'model'=>'shopify-last-visit-utm-v1',
    'visit_at'=>'2026-01-01T10:00:00.000000Z',...$tag];
// Use the live native Sales lead/deal backlink; order association is immutable placement evidence.
$db->prepare("UPDATE tn_leads SET utm_medium='organic' WHERE organization_id=? AND id=?")->execute([$org,$id]);
$commerceSource->facts[$org][$orderId]['placement']['deal_id']=$deal;
$commerceSource->facts[$org][$shopId]['placement']['deal_id']=null;
$dedupCommerce=new \Domains\Marketing\Application\Service\MarketingCommerceAttributionService($campaignService,$plans,$commerceSource,$commerceStore,$store,$modules,null,$sales);
$dedupCommerce->capture($actor,$c2,null,[$orderId],'m4-linked-order-capture');
$d=$dedupCommerce->report($actor,$c2,null,'2026-01-01','2026-01-02');
$assert($d['deduplicated_against_deals']&&$d['deduplication']['captured_leads']===1&&$d['deduplication']['won_deals']===1
    &&$d['deduplication']['overlapping_won_deals']===1&&$d['deduplication']['unique_outcomes']===1,'Linked Lead/Deal/Order counted twice.');
$db->prepare("UPDATE tn_leads SET utm_medium='email' WHERE organization_id=? AND id=?")->execute([$org,$id]);
$d=$dedupCommerce->report($actor,$c2,null,'2026-01-01','2026-01-02');
$assert(!$d['deduplicated_against_deals']&&$d['deduplication']['unique_outcomes']===null&&$d['deduplication']['unresolved_lead_evidence']===1,'Lead drift silently counted.');
$db->prepare("UPDATE tn_leads SET utm_medium='organic' WHERE organization_id=? AND id=?")->execute([$org,$id]);
$receipt=$commerce->capture($actor,$c2,null,[$orderId,$shopId,$badId],'m4-commerce-capture');
$d=$dedupCommerce->report($actor,$c2,null,'2026-01-01','2026-01-02');
$assert(!$d['deduplicated_against_deals']&&$d['deduplication']['overlapping_won_deals']===1
    &&$d['deduplication']['unique_outcomes']===null&&$d['deduplication']['unresolved_order_links']===1,'Unlinked provider order assumed distinct.');
echo "Marketing captured Lead/Deal/Order deduplication and unresolved provider relationship PASS\n";
$assert(array_column($receipt['results'],'state','order_id')===[$orderId=>'RECORDED',$shopId=>'RECORDED',$badId=>'REJECTED'],'Exact commerce attribution failed.');
$assert($commerce->capture($actor,$c2,null,[$shopId,$badId,$orderId],'m4-commerce-capture')===$receipt,'Commerce capture replay changed.');
$r=$commerce->report($actor,$c2,null,'2026-01-01','2026-01-02');
$assert($r['placed_orders']===2&&$r['placed_order_value']===[['currency'=>'EUR','minor'=>12550],['currency'=>'USD','minor'=>2000]]
    &&$r['recognized_revenue']===null&&$r['roas']===null&&!$r['deduplicated_against_deals'],'Commerce value or money semantics invalid.');
$assert($r['lifecycle']['coverage']==='UNAVAILABLE'&&$r['lifecycle']['unavailable_count']===2,'Missing lifecycle source represented as placed orders.');
$ev=static fn(string $id,int $revision,string $type,string $before,string $after,int $qty):array=>[
    'schema_version'=>'1.0.0','organization_id'=>$org,'order_id'=>$orderId,'event_id'=>$id,'revision'=>$revision,
    'event_type'=>'sales.commerce.order.'.$type,'occurred_at'=>'2026-01-02T11:00:00.000000Z',
    'previous_status'=>$before,'status'=>$after,'effect_quantity'=>$qty];
$events=[$ev(str_repeat('4',32),2,'confirm','placed','confirmed',0),
    $ev(str_repeat('5',32),3,'fulfill','confirmed','confirmed',2),
    $ev(str_repeat('6',32),4,'cancel','confirmed','cancelled',1),
    $ev(str_repeat('7',32),5,'return','cancelled','cancelled',1)];
$commerceSource->lifecycleFacts[$org][$orderId]=['order_revision'=>5,'events'=>$events,'next_after_revision'=>5,'has_more'=>false];
$commerceSource->lifecycleFacts[$org][$shopId]=['order_revision'=>1,'events'=>[],'next_after_revision'=>1,'has_more'=>false];
$lifeReport=$commerce->report($actor,$c2,null,'2026-01-01','2026-01-02');
$assert($lifeReport['placed_orders']===2&&$lifeReport['lifecycle']['coverage']==='COMPLETE_CAPTURED_COHORT'&&
    $lifeReport['lifecycle']['current_status_counts']['cancelled']===1&&$lifeReport['lifecycle']['orders_with_returns']===1&&
    $lifeReport['recognized_revenue']===null,'Lifecycle correction rewrote placement or manufactured revenue.');
$commerceSource->lifecycleFacts[$org][$orderId]['events'][1]['event_id']=$events[0]['event_id'];
$lifeReport=$commerce->report($actor,$c2,null,'2026-01-01','2026-01-02');
$assert($lifeReport['lifecycle']['coverage']==='PARTIAL'&&$lifeReport['lifecycle']['invalid_count']===1&&
    $lifeReport['lifecycle']['current_status_counts']['cancelled']===0,'Invalid lifecycle replay counted.');
$commerceSource->lifecycleFacts[$org][$orderId]['events']=$events;
$commerceSource->facts[$org][$shopId]['journey']=['state'=>'conflict'];
$r=$commerce->report($actor,$c2,null,'2026-01-01','2026-01-02');
$assert($r['placed_orders']===1&&$r['unresolved_captured_orders']===1,'Conflicting source not quarantined.');
$assert($commerceStore->list($other,$c2,null,'2026-01-01','2026-01-02')===[],'Cross tenant commerce evidence exposed.');
$commerceSource->available=false;$blocked=false;
try{$commerce->report($actor,$c2,null,'2026-01-01','2026-01-02');}catch(DomainException){$blocked=true;}
$assert($blocked,'Missing commerce source misrepresented as zero.');
echo "Marketing M4.10 commerce exact placement, delayed source fixture, atomic receipts, currency and conflict PASS\n";

$commerceSource->available=true;
$commerceSource->facts[$org][$shopId]['journey']=['state'=>'observed','evidence_hash'=>str_repeat('f',64),'model'=>'shopify-last-visit-utm-v1','visit_at'=>'2026-01-01T10:00:00.000000Z',...$tag];
$financial=new class implements \Domains\Marketing\Application\Contract\MarketingCommerceFinancialReadPort{
    public array $facts=[];
    public function financial(\Kernel\Tenant\Model\TenantContext $actor,string $orderId):?array{return $this->facts[$orderId]??null;}
};
$financial->facts[$orderId]=['schema_version'=>'1.0.0','organization_id'=>$org,'order_id'=>$orderId,'currency'=>'EUR','evidence_reference'=>'fixture-finance-1','revision'=>1,
    'captured_amount'=>'100.00','refunded_amount'=>'15.00','chargeback_amount'=>'5.00','net_collected_amount'=>'80.00','outstanding_amount'=>'25.50'];
$withFinance=new \Domains\Marketing\Application\Service\MarketingCommerceAttributionService($campaignService,$plans,$commerceSource,$commerceStore,$store,$modules,$financial);
$f=$withFinance->report($actor,$c2,null,'2026-01-01','2026-01-02');
$assert($f['financial_evidence_status']==='PARTIAL'&&$f['net_cash_received']===null&&$f['confirmed_financial_totals'][0]['net_collected_minor']===8000,'Partial Finance evidence promoted to complete cash.');
$financial->facts[$shopId]=[...$financial->facts[$orderId],'order_id'=>$shopId,'currency'=>'USD','captured_amount'=>'20.00','refunded_amount'=>'0.00','chargeback_amount'=>'0.00','net_collected_amount'=>'20.00','outstanding_amount'=>'0.00'];
$f=$withFinance->report($actor,$c2,null,'2026-01-01','2026-01-02');
$assert($f['financial_evidence_status']==='COMPLETE_CAPTURED_COHORT'&&$f['net_cash_received']===[['currency'=>'EUR','minor'=>8000],['currency'=>'USD','minor'=>2000]]&&$f['recognized_revenue']===null&&$f['roas']===null,'Exact confirmed collection coverage invalid.');
$financial->facts[$shopId]['net_collected_amount']='99.00';
$f=$withFinance->report($actor,$c2,null,'2026-01-01','2026-01-02');
$assert($f['financial_evidence_invalid_count']===1&&$f['net_cash_received']===null,'Broken collection arithmetic accepted.');
echo "Marketing Commerce Finance contract fixtures: partial, exact net collection, separate currency and invalid evidence PASS\n";

// Historical fixture: native persisted Paid Plan and Sales lead/deal, independent delayed Shopify read-port fact.
// Seeding a historical immutable snapshot is test-only, not a production backdated plan admission.
$lateCampaign=$campaigns->createDraft($org,'42',(new MarketingDraftPolicy())->validate([
    'name'=>'M4 late paid conversion fixture','business_objective'=>'Historical acquisition cohort']),'m4-late-campaign')['id'];
$lateCreative=$planning->createCreative($actor,$lateCampaign,['headline'=>'Late fixture creative','primary_text'=>'Synthetic late commerce fixture',
    'call_to_action'=>'Read more','destination_url'=>'https://example.com/catalog','language'=>'uk'],'m4-late-creative');
$lateAudience=$planning->saveAudience($actor,null,null,['name'=>'Late fixture audience','target_spec'=>[
    'market_countries'=>['UA'],'audience_hypothesis'=>'Historical fixture only']],'m4-late-audience');
$lateAdset=$planning->saveAdset($actor,$lateCampaign,1,null,null,['name'=>'Late fixture traffic','audience_id'=>$lateAudience['id'],
    'audience_revision'=>$lateAudience['revision'],'creatives'=>[['creative_id'=>$lateCreative['id'],'revision'=>$lateCreative['revision']]],
    'placements'=>['AUTOMATIC'],'optimization'=>'TRAFFIC','planned_daily_budget'=>'10.00','currency'=>'USD'],'m4-late-adset');
$historicalPlan=(new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidLaunchPlanStore($db))->create($org,'42','m4-late-plan',
    hash('sha256','m4 late historical fixture'),['campaign_id'=>$lateCampaign,'campaign_revision'=>1,'adset_id'=>$lateAdset['id'],
        'adset_revision'=>$lateAdset['revision'],'adset_content_hash'=>$lateAdset['content_hash'],'account_id'=>$account['id'],
        'account_revision'=>$account['revision'],'currency'=>'USD','account_timezone'=>'UTC',
        'period_start'=>'2026-01-01','period_end'=>'2026-01-03','days'=>3,'daily_minor'=>1000,'lifetime_minor'=>3000]);
$latePlan=$historicalPlan['id'];
$db->prepare("INSERT INTO tn_leads (organization_id,full_name,status,utm_source,utm_medium,utm_campaign,created_at) VALUES (?,'Late lead fixture','qualified','meta','paid_social',?,'2026-01-02 10:00:00')")->execute([$org,$latePlan]);
$lateLead=(string)$db->lastInsertId();
$db->prepare("INSERT INTO tn_leads (organization_id,full_name,status,utm_source,utm_medium,utm_campaign,created_at) VALUES (?,'Expired UTM fixture','new','meta','paid_social',?,'2026-01-04 10:00:00')")->execute([$org,$latePlan]);
$expiredLead=(string)$db->lastInsertId();
$db->prepare("INSERT INTO tn_client_cases (organization_id,public_id,person_id,type,title,status,inbound_request_id,pipeline_id,stage_id,deal_value,currency,closed_at) VALUES (?,?,?,'buy','Late fixture deal','closed',?,?,?,50.00,'USD','2026-01-10 10:00:00')")->execute([$org,'CC-'.bin2hex(random_bytes(5)),$person,$lateLead,$pipeline,$stage]);
$lateDeal=(int)$db->lastInsertId();
$db->prepare('UPDATE tn_leads SET client_case_id=? WHERE organization_id=? AND id=?')->execute([$lateDeal,$org,$lateLead]);
$lateCapture=$analytics->capture($actor,$lateCampaign,$latePlan,[$lateLead,$expiredLead],'m4-late-lead-capture');
$lateStates=array_column($lateCapture['results'],'state','lead_id');
$assert($lateStates[$lateLead]==='RECORDED'&&$lateStates[$expiredLead]==='REJECTED','Paid Lead expiry fence not applied on native capture.');
$lateOrder=bin2hex(random_bytes(16));
$commerceSource->facts[$org][$lateOrder]=$fact($lateOrder,bin2hex(random_bytes(16)),'shopify','USD','50.00',[]);
$commerceSource->facts[$org][$lateOrder]['placement']['occurred_at']='2026-01-10T10:00:00.000000Z';
$commerceSource->facts[$org][$lateOrder]['placement']['deal_id']=null;
$commerceSource->facts[$org][$lateOrder]['journey']=['state'=>'observed','evidence_hash'=>str_repeat('e',64),
    'model'=>'shopify-last-visit-utm-v1','visit_at'=>'2026-01-02T10:00:00.000000Z',
    'utm_campaign'=>$latePlan,'utm_source'=>'meta','utm_medium'=>'paid_social'];
$assert($withFinance->capture($actor,$lateCampaign,$latePlan,[$lateOrder],'m4-late-order-capture')['results'][0]['state']==='RECORDED',
    'Bounded delayed order capture failed.');
$economics=new \Domains\Marketing\Application\Service\MarketingEconomicsService($plans,$analytics,$withFinance,$finance);
$early=$economics->report($actor,$latePlan,null);
$late=$economics->report($actor,$latePlan,null,'2026-01-10');
$assert($early['captured_leads']===1&&$early['won_deals']===0&&$early['placed_orders']===0
    &&$late['window_end']==='2026-01-03'&&$late['conversion_through']==='2026-01-10'&&$late['captured_leads']===1
    &&$late['won_deals']===1&&$late['placed_orders']===1&&$late['settled_media_minor']===null&&$late['roas']===null,
    'Late conversion economics changed acquisition or invented financial evidence.');
echo "Marketing M4 native historical cohort, expired paid UTM, bounded late Deal/Order economics PASS\n";

// Real repository readback runs only on unified Sales/Marketing checkout; explicitly report missing dependency.
if(interface_exists(\Domains\Sales\Application\Contract\SalesOrderJourneyStore::class)){
    $nativeRepo=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOrderRepository($db);
    $nativeId=bin2hex(random_bytes(16));$eventId=bin2hex(random_bytes(16));
    $placement=$fact($nativeId,$eventId,'shopify','EUR','25.00',[])['placement'];
    $placement['store_reference']=str_repeat('a',32);$placement['external_order_id']='gid://shopify/Order/123';
    $placement['deal_id']=null;$placement['correlation_id']='m4-commerce-native-test';
    $db->beginTransaction();
    try{
        $nativeRepo->create($org,['order_id'=>$nativeId,'owner_id'=>42,'deal_id'=>null,'customer_id'=>null,'channel'=>'shopify',
            'store_reference'=>$placement['store_reference'],'external_order_id'=>$placement['external_order_id'],'currency'=>'EUR',
            'subtotal'=>'25.00','discount'=>'0.00','tax'=>'0.00','tax_included'=>false,'shipping'=>'0.00','total'=>'25.00',
            'attribution'=>[],'placed_at'=>'2026-01-02 10:00:00.000000','created_by'=>42],
            [['line_no'=>1,'sku'=>'FIXTURE','title'=>'Fixture','product_id'=>null,'variant_id'=>null,'quantity'=>1,'unit_price'=>'25.00','subtotal'=>'25.00']],$placement);
        $nativeRepo->recordJourney($org,$nativeId,$placement['store_reference'],$placement['external_order_id'],
            ['model'=>'shopify-last-visit-utm-v1','visit_at'=>'2026-01-01T10:00:00.000000Z',...$tag]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    $nativeSource=new \App\Infrastructure\Marketing\CommerceOrderReadAdapter($modules,$nativeRepo,$nativeRepo,
        $nativeRepo instanceof \Domains\Sales\Application\Contract\SalesCommerceOrderLifecycleEvidencePort?$nativeRepo:null);
    $nativeCommerce=new \Domains\Marketing\Application\Service\MarketingCommerceAttributionService($campaignService,$plans,$nativeSource,$commerceStore,$store,$modules);
    $nativeFact=$nativeSource->get($actor,$nativeId);
    $assert(array_key_exists('deal_id',$nativeFact['placement'])&&$nativeFact['placement']['deal_id']===null,'Native placement deal relationship lost.');
    $r=$nativeCommerce->capture($actor,$c2,null,[$nativeId],'m4-native-order-join');
    $assert($r['results'][0]['state']==='RECORDED','Native Sales -> Marketing commerce integration failed.');
    echo "Marketing M4.10 native Sales repository -> Marketing attribution PASS\n";
}else{
    $adapter=new \App\Infrastructure\Marketing\CommerceOrderReadAdapter($modules);
    $missing=false;try{$adapter->assertAvailable($actor);}catch(DomainException){$missing=true;}
    $assert($missing,'Absent Sales commerce package did not fail closed.');
    echo "Marketing M4.10 combined native Sales repository acceptance DEFERRED: source branch not installed\n";
}

$candidateRead=new \Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesAttributionCandidateReadModel($db);
$candidateAdapter=new \App\Infrastructure\Marketing\AttributionCandidateReadAdapter($candidateRead,$modules);
$sweepCampaign=$campaigns->createDraft($org,'42',(new MarketingDraftPolicy())->validate(['name'=>'M4 sweep fixture','business_objective'=>'Native conversion collection']),'m4-sweep-campaign')['id'];
$insertLead=$db->prepare("INSERT INTO tn_leads (organization_id,full_name,status,utm_source,utm_medium,utm_campaign,created_at) VALUES (?,'Private sweep fixture','new','google','organic',?,'2026-02-01 10:00:00')");
for($i=0;$i<51;$i++)$insertLead->execute([$org,$sweepCampaign]);
$db->prepare('INSERT INTO cos_organizations (id,name,status) VALUES (?,?,"ACTIVE")')->execute([$other,'Other sweep fixture']);
$insertLead->execute([$other,$sweepCampaign]);
$sweepOrderRepo=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOrderRepository($db);
$sweepOrderSource=new \App\Infrastructure\Marketing\CommerceOrderReadAdapter($modules,$sweepOrderRepo,$sweepOrderRepo,$sweepOrderRepo);
$sweep=new \Domains\Marketing\Application\Service\MarketingAttributionSweepService($campaignService,$plans,$candidateAdapter,$sales,$sweepOrderSource,$store,$commerceStore,$modules);
$sourcePage=$candidateRead->page($org,'lead','2026-02-01','2026-02-01',null,50);
$assert(count($sourcePage['ids'])===50&&$sourcePage['next_after']!==null,'Native Sales source page invalid: '.json_encode(['count'=>count($sourcePage['ids']),'has_more'=>$sourcePage['next_after']!==null],JSON_THROW_ON_ERROR));
$firstLead=$sales->lead($actor,$sourcePage['ids'][0]);
$assert($firstLead!==null&&$firstLead['utm_campaign']===$sweepCampaign,'Native lead source tag mismatch.');
$firstEvidence=Policy::evidence($firstLead,$sweepCampaign,null);
$assert(substr($firstEvidence['created_at'],0,10)==='2026-02-01','Native source date mismatch.');
$page1=$sweep->sweep($actor,$sweepCampaign,null,'lead','2026-02-01','2026-02-01',null,'m4-sweep-first');
$assert($page1['scanned']===50&&$page1['recorded_or_already_present']===50&&!$page1['page_exhausted']&&!$page1['complete_coverage'],'Sweep first page or tenant fence invalid: '.json_encode($page1,JSON_THROW_ON_ERROR));
$page2=$sweep->sweep($actor,$sweepCampaign,null,'lead','2026-02-01','2026-02-01',$page1['next_after'],'m4-sweep-second');
$assert($page2['scanned']===1&&$page2['recorded_or_already_present']===1&&$page2['page_exhausted']&&!$page2['complete_coverage'],'Sweep cursor skipped or duplicated rows: '.json_encode($page2,JSON_THROW_ON_ERROR));
$assert($sweep->sweep($actor,$sweepCampaign,null,'lead','2026-02-01','2026-02-01',null,'m4-sweep-first')===$page1,'Sweep receipt replay changed.');
$deny=false;try{$sweep->sweep($actor,$sweepCampaign,null,'lead','2026-02-01','2026-02-01',$page1['next_after'],'m4-sweep-first');}catch(DomainException){$deny=true;}
$assert($deny,'Sweep replay key accepted changed cursor.');
$assert(count($store->list($org,$sweepCampaign,'2026-02-01','2026-02-01',501,0))===51,'Sweep native evidence coverage failed.');
$scan=$sweep->sweep($actor,$c2,null,'order','2026-01-02','2026-01-02',null,'m4-sweep-native-orders');
$assert($scan['scanned']>=1&&$scan['recorded_or_already_present']>=1&&!$scan['financial_mutation'],'Native order sweep failed.');
$assert($candidateRead->page(strtoupper($org),'lead','2026-02-01','2026-02-01',null,50)['ids']===[],'Case-variant tenant leaked.');
echo "Marketing M4 governed sweep service native keyset discovery, source capture, replay and tenant fence PASS\n";

$multi=$sweep->sweep($actor,$sweepCampaign,null,'lead','2026-02-01','2026-02-01',null,'m4-sweep-multi',5);
$assert($multi['scanned']===51&&$multi['pages_processed']===2&&$multi['max_pages']===5&&$multi['recorded_or_already_present']===51&&$multi['page_exhausted'],'Bounded automatic page continuation failed.');
$assert($store->receipt($org,'m4-sweep-multi')['receipt']===$multi&&$store->receipt($other,'m4-sweep-multi')===null,'Native sweep receipt readback or tenant fence failed.');
$assert($sweep->sweep($actor,$sweepCampaign,null,'lead','2026-02-01','2026-02-01',null,'m4-sweep-multi',5)===$multi,'Multipage replay changed receipt.');
$deny=false;try{$sweep->sweep($actor,$sweepCampaign,null,'lead','2026-02-01','2026-02-01',null,'m4-sweep-multi',4);}catch(DomainException){$deny=true;}
$assert($deny,'Sweep replay changed approved page budget.');
$deny=false;try{$sweep->sweep($actor,$sweepCampaign,null,'lead','2026-02-01','2026-02-01',null,'m4-sweep-invalid',6);}catch(DomainException){$deny=true;}
$assert($deny&&$store->receipt($org,'m4-sweep-invalid')===null,'Unbounded page budget admitted.');
echo "Marketing M4 multipage atomic native receipt, tenant fence, bounded continuation and replay PASS\n";

$historyService=new \Domains\Marketing\Application\Service\MarketingTouchAttributionService($nativeSource,$campaignService,$plans,$modules);
$assert($historyService->report($actor,$nativeId,'linear')['status']==='UNAVAILABLE','Missing history fabricated attribution.');
$touch1=str_repeat('1',64);$touch2=str_repeat('2',64);$touch3=str_repeat('3',64);
$history=['model'=>'shopify-journey-moments-v1','first_touch'=>$touch1,'last_touch'=>$touch3,'touches'=>[
    ['touch_id'=>$touch1,'visit_at'=>'2026-01-01T08:00:00.000000Z','tags'=>['utm_campaign'=>$campaign,'utm_source'=>'google','utm_medium'=>'organic']],
    ['touch_id'=>$touch2,'visit_at'=>'2026-01-01T09:00:00.000000Z','tags'=>null],
    ['touch_id'=>$touch3,'visit_at'=>'2026-01-01T10:00:00.000000Z','tags'=>['utm_campaign'=>$c2,'utm_source'=>'google','utm_medium'=>'organic']]]];
$beforeJourney=$nativeRepo->journey($org,$nativeId);
$db->beginTransaction();$nativeRepo->recordJourney($org,$nativeId,$placement['store_reference'],$placement['external_order_id'],$history);$db->commit();
$assert($nativeRepo->journey($org,$nativeId)===$beforeJourney,'Full history rewrote last visit evidence.');
$first=$historyService->report($actor,$nativeId,'first_touch');$last=$historyService->report($actor,$nativeId,'last_touch');$linear=$historyService->report($actor,$nativeId,'linear');
$assert($first['allocations'][0]['campaign_id']===$campaign&&$first['allocations'][0]['minor']===2500&&$last['allocations'][0]['campaign_id']===$c2&&$last['allocations'][0]['minor']===2500,'First/last touch models invalid.');
$assert($linear['touch_count']===3&&$linear['unassigned_minor']===833&&array_sum(array_column($linear['allocations'],'minor'))===1667&&$linear['recognized_revenue']===null,'Linear allocation failed exact conservation or unknown share.');
$assert($nativeRepo->touchHistory($other,$nativeId)===null,'Cross tenant touch history leak.');
$changed=$history;$changed['touches'][1]['tags']=['utm_campaign'=>$c2,'utm_source'=>'google','utm_medium'=>'organic'];
$db->beginTransaction();$nativeRepo->recordJourney($org,$nativeId,$placement['store_reference'],$placement['external_order_id'],$changed);$db->commit();
$assert($historyService->report($actor,$nativeId,'linear')['status']==='CONFLICT'&&$nativeRepo->journey($org,$nativeId)===$beforeJourney,'History conflict silently reallocated or poisoned last touch.');
echo "Marketing M4.11 native history independent of last touch, first/last/linear exact allocation and conflict PASS\n";

$monCurrent=$mediaStore->commit($org,'42','m5-monitor-now',hash('sha256','m5-now'),$account,'campaign','2026-02-08','2026-02-14',[
    [...$base,'date_start'=>'2026-02-08','date_stop'=>'2026-02-14','spend'=>'150.00','impressions'=>'2000','clicks'=>'40'],
    [...$base,'campaign_id'=>'90003','date_start'=>'2026-02-08','date_stop'=>'2026-02-14','spend'=>'3.00','impressions'=>'20','clicks'=>'1']]);
$monBaseline=$mediaStore->commit($org,'42','m5-monitor-base',hash('sha256','m5-base'),$account,'campaign','2026-02-01','2026-02-07',[
    [...$base,'date_start'=>'2026-02-01','date_stop'=>'2026-02-07','spend'=>'100.00','impressions'=>'2000','clicks'=>'100'],
    [...$base,'campaign_id'=>'90004','date_start'=>'2026-02-01','date_stop'=>'2026-02-07','spend'=>'10.00','impressions'=>'1000','clicks'=>'40']]);
$monitor=new \Domains\Marketing\Application\Service\MarketingOptimizationMonitor(new \Domains\Marketing\Application\Service\MarketingMediaAnalyticsService($accounts,$provider,$mediaStore,$modules));
$mon=$monitor->report($actor,$account['id'],'campaign','2026-02-08','2026-02-14','2026-02-01','2026-02-07');
$states=array_column($mon['items'],'state','external_id');
$assert($mon['alert_count']===1&&$mon['insufficient_count']===2&&$states['90001']==='REVIEW_REQUIRED'&&$states['90003']==='NO_BASELINE'&&$states['90004']==='NO_CURRENT_OBSERVATION'
    &&count($mon['items'][0]['flags'])===4&&!$mon['provider_mutation'],'M5 source comparison missed anomalies or inferred missing delivery.');
echo "Marketing M5 native media batch monitoring, matched anomalies and unavailable scopes PASS\n";
