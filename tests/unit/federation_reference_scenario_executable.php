<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__,2).'/symfony/src/Persistence/Federation/FederationCapabilityBindingResolver.php';
require dirname(__DIR__,2).'/symfony/src/Persistence/Federation/FederationReferenceScenarioReadiness.php';

use App\Persistence\Federation\FederationCapabilityBindingResolver;
use App\Persistence\Federation\FederationReferenceScenarioReadiness;
use Kernel\Action\Action;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

$growthIds=['growth.market.discovery','growth.candidate.qualify','growth.handoff.prepare','growth.handoff.target.sales'];
$documentsIds=['documents.proposal.prepare'];
$fromRealManifest = static function(string $domain,array $ids):ModuleContributions {
    $raw=require dirname(__DIR__,2).'/app/Domains/'.ucfirst($domain).'/module.php';
    $contracts=array_values(array_filter($raw['contributions']['capability_contracts']??[],
        static fn(array $c):bool=>in_array($c['id']??'',$ids,true)));
    if(count($contracts)!==count($ids))throw new RuntimeException('Missing actual typed contracts for '. $domain);
    return ModuleContributions::fromArray(['capabilities'=>$ids,'capability_contracts'=>$contracts]);
};
$catalog=new ModuleCatalog([
    new ModuleDefinition(new ModuleManifest('growth','Growth','1.0.0'),$fromRealManifest('growth',$growthIds)),
    new ModuleDefinition(new ModuleManifest('sales','Sales','1.0.0'),new ModuleContributions()),
    new ModuleDefinition(new ModuleManifest('documents','Documents','1.0.0'),$fromRealManifest('documents',$documentsIds)),
]);
$stubModule=static fn(string $owner,array $ids):DomainModuleInterface=>new class($owner,$ids) implements DomainModuleInterface,ActionOwningModuleInterface {
    public function __construct(private string $owner,private array $types) {}
    public function name():string{return $this->owner;}
    public function actionTypes():array{return $this->types;}
    public function actionHandlers():array{
        return [new class($this->types) implements ActionHandlerInterface {
            public function __construct(private array $types) {}
            public function supports(string $type):bool{return in_array($type,$this->types,true);}
            public function execute(Action $action):ExecutionResult{return ExecutionResult::failure('Preflight must never execute an Action.');}
        }];
    }
};
$registry=new DomainModuleRegistry([$stubModule('growth',$growthIds),$stubModule('documents',$documentsIds)]);
$states=new class implements ModuleStateRepositoryInterface {
    public bool $sales=true;
    public function enabledOverride(string $org,string $module):?bool {
        return $module==='sales'?$this->sales:true;
    }
    public function setEnabled(string $org,string $module,bool $enabled):void{}
};
$binding=new FederationCapabilityBindingResolver(
    new CanonicalCapabilityCatalog($catalog),$registry,new ActiveModuleResolver($catalog,$states)
);
$preflight=new FederationReferenceScenarioReadiness(new CanonicalCapabilityCatalog($catalog),$binding);
$actor=new TenantContext(UserId::fromString('manager-1'),OrganizationId::fromString('tenant-1'),
    OrganizationRole::fromString('manager'),[
        Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE),
    ]);
$ready=$preflight->inspect($actor);
if($ready['execution_ready']!==true || $ready['business_outcome_verified']!==false
    || count($ready['stages'])!==5
    || array_unique(array_column($ready['stages'],'status'))!==['executable']) {
    throw new RuntimeException('Five real registered Domain Action handlers should pass structural preflight only.');
}
$states->sales=false;
$disabled=$preflight->inspect($actor);
$statuses=array_column($disabled['stages'],'status','stage');
if($disabled['execution_ready']!==false
    || ($statuses['handoff_and_sales_intake']??null)!=='dependent_module_unavailable'
    || ($statuses['prepare_proposals']??null)!=='executable') {
    throw new RuntimeException('Disabled target Sales tenant was not rejected at cross-domain boundary.');
}
echo "Federation five canonical handlers: structural readiness; disabled Sales target denial; never business success PASS.\n";
