<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Kernel\Action\Action;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\Contract\FailClosedWorkflowAdmissionMarkerInterface;
use Kernel\Action\Contract\WorkflowActionWorkerAdmissionInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\Service\ModuleActionExecutionGate;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDefinition;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
spl_autoload_register(static function(string $class)use($root):void {
    if (!str_starts_with($class,'App\\'))return;
    $file=$root.'/symfony/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($file))require $file;
});
$assert=static function(bool $ok,string $msg):void {
    if(!$ok)throw new RuntimeException($msg);
};
$manifest=require $root.'/app/Domains/Property/module.php';
$catalogModules=new ModuleCatalog([ModuleDefinition::fromArray($manifest)]);
$catalog=new CanonicalCapabilityCatalog($catalogModules);
$state=new class implements ModuleStateRepositoryInterface {
    public bool $active=true;
    public function enabledOverride(string $org,string $module):?bool {return $this->active;}
    public function setEnabled(string $org,string $module,bool $active):void {$this->active=$active;}
};
$modules=new ActiveModuleResolver($catalogModules,$state);
$owner=new class implements DomainModuleInterface,ActionOwningModuleInterface {
    public function name():string{return 'property';}
    public function actionTypes():array{return ['property.bulk.create'];}
    public function actionHandlers():array {
        return [new class implements ActionHandlerInterface {
            public function supports(string $actionType):bool{return $actionType==='property.bulk.create';}
            public function execute(Action $action):ExecutionResult {
                throw new LogicException('A binding census must never execute an Action.');
            }
        }];
    }
};
$domains=new DomainModuleRegistry([$owner]);
$closed=new class implements WorkflowActionWorkerAdmissionInterface,FailClosedWorkflowAdmissionMarkerInterface {
    public function assertAuthorized(Action $action):void {
        throw new DomainException('Workflow owned Actions disabled.');
    }
};
$gate=new ModuleActionExecutionGate($domains,$modules,null,$closed);
$assert($gate->workflowAdmissionMode()==='WORKER_DISABLED_FAIL_CLOSED',
    'Runtime gate must attest actually injected fail-closed marker.');
$noGate=new ModuleActionExecutionGate($domains,$modules);
$assert($noGate->workflowAdmissionMode()==='WORKER_ADMISSION_NOT_INSTALLED',
    'Missing worker admission cannot claim any readiness.');
$bindings=new FederationCapabilityBindingResolver($catalog,$domains,$modules);
$gateway=new CanonicalCapabilityGateway($catalog,$modules,$bindings,[],$domains,$gate);
$actor=new TenantContext(
    UserId::fromString('15'),OrganizationId::fromString('cos-test-tenant'),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]
);
$present=$gateway->describe($actor,'property.bulk.create');
$assert($present['binding_witness']==='ACTION_HANDLER_REGISTERED'
    && $present['binding_witness_issue']==='NONE'
    && $present['status']==='workflow_approval_required',
    'Domain registry must attest Action handler independent of approval gate.');
$assert($present['workflow_worker_admission_mode']==='WORKER_DISABLED_FAIL_CLOSED'
    && $present['worker_execution_certified']===false,
    'Action handler registration must never claim actual worker dispatch.');
$read=$gateway->describe($actor,'property.asset.search');
$assert($read['binding_witness']==='UNVERIFIED'
    && $read['binding_witness_issue']==='DOMAIN_ADAPTER_MISSING',
    'Declared Query without real registered adapter must not pass.');
$withNoRuntime=new CanonicalCapabilityGateway(
    $catalog,$modules,$bindings,[],null,null
);
$missing=$withNoRuntime->describe($actor,'property.bulk.create');
$assert($missing['binding_witness']==='UNVERIFIED'
    && $missing['binding_witness_issue']==='DOMAIN_RUNTIME_REGISTRY_UNAVAILABLE'
    && $missing['workflow_worker_admission_mode']==='WORKER_GATE_UNAVAILABLE',
    'Unknown runtime registry must never become a positive witness.');
$state->active=false;
$disabled=$gateway->describe($actor,'property.bulk.create');
$assert($disabled['status']==='unavailable'
    && $disabled['binding_witness']==='ACTION_HANDLER_REGISTERED'
    && $disabled['worker_execution_certified']===false,
    'Handler registration and tenant activation must be independent dimensions.');
$report=$gateway->bindingAudit($actor);
$assert($report['certified']===false
    && $report['workflow_worker_admission_mode']==='WORKER_DISABLED_FAIL_CLOSED'
    && $report['worker_execution_certified']===false,
    'Fleet-level certification must not be inferred from a single runtime.');
echo "Capability runtime exact registered Action/query witness and fail-closed admission PASS.\n";
