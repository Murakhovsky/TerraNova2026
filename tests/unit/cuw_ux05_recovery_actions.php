<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
spl_autoload_register(static function(string $name) use ($root): void {
    if (str_starts_with($name, 'App\\')) {
        $p = $root.'/symfony/src/'.str_replace('\\', '/', substr($name, 4)).'.php';
        if (is_file($p)) require $p;
    }
});

use App\Web\Operations\RecoveryActionPlan;
use App\Web\Experience\Action\{UIActionRegistry,UIActionResolver,UIActionPermissionResolver,TenantUIActionPermissionChecker,UIActionPlacement,RuntimeUIActionProvider};
use App\Web\Experience\Async\AsyncOperationWebProvider;
use App\Web\Experience\Extension\{WebExtensionCatalog,WebExtensionProviderRegistry};
use App\Web\Experience\Extension\Contract\ActionProviderInterface;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use Kernel\Action\Contract\RuntimeActionReadModelInterface;
use Kernel\Queue\Contract\AsyncOperationReadModelInterface;
use Kernel\Queue\AsyncOperationProjection;
use Kernel\Module\{ModuleCatalog,ModuleContributions,ModuleDefinition,ModuleManifest,ModuleExtensionPoint,ModuleExtensionRegistry,ActiveModuleResolver};
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\{OrganizationId,UserId};
use Kernel\Tenant\Model\{Permission,TenantContext,TenantPermissions};

function check(bool $ok,string $message):void {
    if (!$ok) throw new RuntimeException($message);
}

$run='run-1'; $revision=4;
$candidates=['step-1'=>['revision'=>7,'modes'=>['verified_complete','reset_read_only'],'active'=>false]];
$initial=RecoveryActionPlan::build($run,$revision,'1',$candidates,[]);
check(count($initial)===2,'Eligible request descriptors missing.');
check($initial[0]['action']->id!==$initial[1]['action']->id,'Different resolution must have a different action ID.');
check($initial[0]['action']->id===RecoveryActionPlan::build($run,$revision,'1',$candidates,[])[0]['action']->id,'Repeated same intent changes ID.');
check($initial[0]['action']->id!==RecoveryActionPlan::build($run,$revision+1,'1',$candidates,[])[0]['action']->id,'Stale Run revision did not change action ID.');
$candidates['step-1']['revision']=8;
check($initial[0]['action']->id!==RecoveryActionPlan::build($run,$revision,'1',$candidates,[])[0]['action']->id,'Stale Step revision did not change action ID.');
$candidates['step-1']['revision']=7;
$candidates['step-1']['active']=true;
$request=['id'=>str_repeat('a',32),'step'=>'step-1','runRevision'=>4,'stepRevision'=>7,
    'resolution'=>'verified_complete','status'=>'REQUESTED','requestedBy'=>'1','approvedBy'=>'','receipt'=>'action:'.str_repeat('b',32)];
check(RecoveryActionPlan::build($run,4,'1',$candidates,[$request])===[],'Self-approval or duplicate petition offered.');
$second=RecoveryActionPlan::build($run,4,'2',$candidates,[$request]);
check(count($second)===1 && $second[0]['operation']==='approve' && $second[0]['resource']===$request['id'],'Independent approval descriptor incorrect.');
check(RecoveryActionPlan::build($run,5,'2',$candidates,[$request])===[],'Stale request offered.');
check(RecoveryActionPlan::build($run,4,'2',$candidates,[array_replace($request,['receipt'=>''])])===[],'Missing proof offered.');
check(RecoveryActionPlan::build($run,4,'2',$candidates,[array_replace($request,['resolution'=>'cancel_uncertain'])])===[],'Unsupported recovery offered.');
$approved=array_replace($request,['status'=>'APPROVED','approvedBy'=>'2']);
$apply=RecoveryActionPlan::build($run,4,'2',$candidates,[$approved]);
check(count($apply)===1 && $apply[0]['operation']==='apply','Approved reconciliation descriptor missing.');
check(RecoveryActionPlan::build($run,4,'2',$candidates,[array_replace($approved,['approvedBy'=>'1'])])===[],'Self-approved request offered.');
check(RecoveryActionPlan::build($run,4,'2',$candidates,[array_replace($approved,['status'=>'APPLIED'])])===[],'Applied request offered a second time.');

// Mock only the integrator's descriptor contribution; exercise the REAL shared registry/resolver.
$provider=new class($second[0]['action']) implements ActionProviderInterface {
    public function __construct(private object $descriptor){}
    public function serviceId():string{return 'operationsRecoveryActionProvider';}
    public function actions(WebExtensionContext $c,?EntityRef $e=null):array {
        return $c->organizationId==='tenant-a' && $c->activeSection==='operations' && $e?->key()==='operations.workflow:run-1'
            ? [$this->descriptor]:[];
    }
};
$states=new class implements ModuleStateRepositoryInterface {
    public function enabledOverride(string $organizationId,string $moduleId):?bool{return null;}
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void {throw new LogicException('No writes');}
};
$runtime=new class implements RuntimeActionReadModelInterface {
    public function forEntity(string $organizationId,array $targetTypes,string $targetId,int $limit=20):array{return [];}
};
$async=new class implements AsyncOperationReadModelInterface {
    public function recentForOrganization(string $organizationId,int $limit=50):array{return [];}
    public function find(string $organizationId,string $operationId):?AsyncOperationProjection{return null;}
};
$buildResolver=static function(bool $registered)use($provider,$states,$runtime,$async):UIActionResolver{
    $contributions=new ModuleContributions(extensionServices:$registered?[ModuleExtensionPoint::WEB_ACTIONS=>[$provider->serviceId()]]:[]);
    $catalog=new ModuleCatalog([new ModuleDefinition(new ModuleManifest('ops_fixture','Operations fixture','1.0.0',enabledByDefault:true),$contributions)]);
    $registry=new WebExtensionProviderRegistry(new ModuleExtensionRegistry($catalog),new ActiveModuleResolver($catalog,$states),[$provider]);
    return new UIActionResolver(new UIActionRegistry(new WebExtensionCatalog($registry,new AsyncOperationWebProvider($async)),new RuntimeUIActionProvider($runtime)),
        new UIActionPermissionResolver([new TenantUIActionPermissionChecker()]));
};
$actor=static fn(string $org,array $permissions):TenantContext=>new TenantContext(UserId::fromString('2'),OrganizationId::fromString($org),
    OrganizationRole::fromString('manager'),array_map(Permission::fromString(...),$permissions));
$context=new WebExtensionContext('tenant-a','manager','workspace','operations','operations');
$entity=new EntityRef('operations.workflow','run-1');
$allowed=$actor('tenant-a',[TenantPermissions::ACCESS,TenantPermissions::MANAGE]);
check($buildResolver(false)->resolve($allowed,$context,$entity,UIActionPlacement::WORKSPACE_SECONDARY)===[],'Unregistered descriptor leaked.');
$resolver=$buildResolver(true);
$resolved=$resolver->resolve($allowed,$context,$entity,UIActionPlacement::WORKSPACE_SECONDARY);
check(count($resolved)===1 && $resolved[0]->enabled,'Registered authorized descriptor not allowed.');
$denied=$resolver->resolve($actor('tenant-a',[TenantPermissions::ACCESS]),$context,$entity,UIActionPlacement::WORKSPACE_SECONDARY);
check(count($denied)===1 && !$denied[0]->enabled,'Missing manage permission did not deny action.');
check($resolver->resolve($actor('tenant-b',[TenantPermissions::ACCESS,TenantPermissions::MANAGE]),
    new WebExtensionContext('tenant-b','manager','workspace','operations','operations'),$entity,UIActionPlacement::WORKSPACE_SECONDARY)===[],'Foreign descriptor leaked.');
echo "UX-05 recovery action fences, independent approval and resolver registration/permission contract passed.\n";
