<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Infrastructure\Capability\SalesWorkspaceSummaryAdapter;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesWorkspaceScope;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAccessControl;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesWorkspaceReadModel;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesWorkspaceOperationalReadModel;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\{ActiveModuleResolver,CanonicalCapabilityCatalog,DomainModuleRegistry,ModuleCatalog,ModuleDefinition};
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Shared\Domain\{OrganizationId,UserId};
use Kernel\Tenant\Model\{Permission,TenantContext,TenantPermissions};

$root=dirname(__DIR__,2);
require getenv('COS_TEST_AUTOLOAD')?:$root.'/symfony/vendor/autoload.php';
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$reject=static function(callable $fn):void{try{$fn();}catch(DomainException){return;}throw new RuntimeException('Unauthorized scope accepted.');};
$db=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',getenv('DB_HOST')?:'127.0.0.1',(int)(getenv('DB_PORT')?:3306),getenv('DB_DATABASE')?:'cos'),getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pipeline=$db->query('SELECT p.organization_id,p.id,p.initial_stage_id FROM sales_pipelines p JOIN sales_pipeline_stages s ON s.id=p.initial_stage_id AND s.organization_id=p.organization_id AND s.pipeline_id=p.id WHERE p.status="ACTIVE" AND s.status="ACTIVE" ORDER BY p.organization_id LIMIT 1')->fetch();
$check(is_array($pipeline),'Sales pipeline fixture missing.');$org=$pipeline['organization_id'];$pid=$pipeline['id'];$stage=$pipeline['initial_stage_id'];$token=bin2hex(random_bytes(6));
$db->beginTransaction();
try{
    $makeUser=static function(string $suffix)use($db,$org,$token):int{
        $db->prepare('INSERT INTO tn_users(organization_id,email,password_hash,full_name,role,status) VALUES (?,?,?,?, "manager","active")')->execute([$org,$token.'-'.$suffix.'@example.test',password_hash($token,PASSWORD_DEFAULT),'Summary fixture']);
        $id=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO cos_organization_memberships(organization_id,user_id,role,status) VALUES (?,?,"manager","ACTIVE")')->execute([$org,$id]);return $id;
    };
    $owner=$makeUser('owner');$other=$makeUser('other');
    $db->prepare('INSERT INTO tn_people(organization_id,public_id,full_name) VALUES (?,?,?)')->execute([$org,'P-'.$token,'Private fixture person']);$person=(int)$db->lastInsertId();
    $q=$db->prepare('INSERT INTO tn_client_cases(organization_id,public_id,person_id,type,title,status,stage,pipeline_id,stage_id,probability,priority,assigned_user_id,deal_value,currency,budget_max) VALUES (?,?,?,"buy","Private title",?,"new",?,?,50,"normal",?,?,?,99999)');
    $insert=static function(int $i,int $who,?string $money,string $currency,string $status='active')use($q,$org,$token,$person,$pid,$stage):void{$q->execute([$org,'D-'.$token.'-'.$i,$person,$status,$pid,$stage,$who,$money,$currency]);};
    for($i=0;$i<251;$i++)$insert($i,$owner,'10.01','USD');
    $insert(251,$owner,'20.02','EUR');$insert(252,$owner,null,'UAH');$insert(253,$owner,'100.00','USD','closed');$insert(254,$other,'333.00','UAH');
    $read=new MysqlSalesWorkspaceReadModel($db);$scope=new MysqlSalesWorkspaceScope($db,new MysqlSalesAccessControl($db));
    $modules=new ModuleCatalog([ModuleDefinition::fromArray(require $root.'/app/Domains/Sales/module.php')]);$catalog=new CanonicalCapabilityCatalog($modules);
    $active=new ActiveModuleResolver($modules,new class implements ModuleStateRepositoryInterface{
        public bool $enabled=true;
        public function enabledOverride(string $organizationId,string $moduleId):?bool{return $this->enabled;}
        public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void{$this->enabled=$enabled;}
    });
    $gateway=new CanonicalCapabilityGateway($catalog,$active,new FederationCapabilityBindingResolver($catalog,new DomainModuleRegistry([]),$active),[new SalesWorkspaceSummaryAdapter($scope,$read)]);
    $actor=static fn(string $tenant,int $user)=>new TenantContext(UserId::fromString((string)$user),OrganizationId::fromString($tenant),OrganizationRole::fromString('manager'),[Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]);
    $manager=$actor($org,$owner);
    $invoke=static fn(array $in)=>$gateway->invoke($manager,'sales.workspace.summary.read',$in,'trusted_read','','summary-test');
    $summary=$invoke(['pipeline_id'=>$pid]);
    $check($summary['scope']['owner_id']===$owner&&$summary['counts']['active_deals']===253&&$summary['counts']['monetary_deals']===252,'Open scope/coverage mismatch.');
    $by=array_column($summary['money_by_currency'],null,'currency');
    $check(count($by)===2&&$by['USD']['deal_count']===251&&$by['USD']['pipeline_value']==='2512.51'&&$by['USD']['weighted_value']==='1256.26'&&$by['EUR']['pipeline_value']==='20.02','Currency/decimal or page truncation defect.');
    $check(!str_contains(json_encode($summary,JSON_THROW_ON_ERROR),'Private'),'PII exposed by aggregate contract.');
    $check($summary['stages'][0]['deal_count']===253,'Stage population truncated.');
    $reject(fn()=>$invoke(['scope'=>'tenant']));
    $reject(fn()=>$invoke(['scope'=>'owner','owner_id'=>$other]));
    $reject(fn()=>$invoke(['scope'=>'self','owner_id'=>$other]));
    $reject(fn()=>$gateway->invoke($actor('foreign-'.$token,$owner),'sales.workspace.summary.read',[],'trusted_read','','foreign'));
    $db->prepare('INSERT INTO sales_user_capabilities(organization_id,user_id,capability,status,granted_by,created_at,updated_at) VALUES (?,?,"sales.director.view","ACTIVE",?,NOW(6),NOW(6))')->execute([$org,$owner,(string)$owner]);
    $check($invoke(['scope'=>'owner','owner_id'=>$other])['money_by_currency'][0]['pipeline_value']==='333.00','Director owner filter failed.');
    $all=$invoke(['scope'=>'tenant','pipeline_id'=>$pid]);$check($all['scope']['owner_id']===null&&$all['counts']['active_deals']>=254,'Director tenant scope failed.');
    $db->prepare('DELETE FROM sales_user_capabilities WHERE organization_id=? AND user_id=? AND capability="sales.director.view"')->execute([$org,$owner]);
    $reject(fn()=>$invoke(['scope'=>'tenant']));
    $teams=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesTeamAdministration($db);
    $team=$teams->createTeam($org,['name'=>'Summary team','code'=>'S5_'.strtoupper($token)],(string)$owner);
    $teamId=$team['id'];
    $teams->setMember($org,$teamId,$owner,['role'=>'LEAD','status'=>'ACTIVE','assignment_enabled'=>true],(string)$owner);
    $teams->setMember($org,$teamId,$other,['role'=>'MEMBER','status'=>'ACTIVE','assignment_enabled'=>true],(string)$owner);
    $check($invoke(['scope'=>'owner','owner_id'=>$other])['counts']['active_deals']===1,'Active team lead scope failed.');
    $db->prepare('UPDATE sales_teams SET status="DISABLED" WHERE organization_id=? AND id=?')->execute([$org,$teamId]);
    $reject(fn()=>$invoke(['scope'=>'owner','owner_id'=>$other]));
    $dash=$read->dashboard($org,$owner);$check($dash['kpis']['pipeline_value']===null&&$dash['kpis']['money_by_currency']===$summary['money_by_currency']&&$dash['metrics']===[],'Dashboard parity/tenant leakage.');
    $operational=new MysqlSalesWorkspaceOperationalReadModel($db,$read);
    $pipelines=$operational->pipelines($org);$p=array_values(array_filter($pipelines,fn($x)=>$x['id']===$pid))[0];
    $s=array_values(array_filter($p['stages'],fn($x)=>$x['id']===$stage))[0];$check($s['deal_count']>=254&&count($s['money_by_currency'])>=3&&$s['pipeline_value']===null,'UI pipeline aggregate incorrect.');
    echo "Sales S5 scoped currency-separated full-population workspace summary PASS.\n";
}finally{$db->rollBack();}
