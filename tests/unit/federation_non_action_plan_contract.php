<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
// Fast CI installs the root composer project, not symfony/vendor. The
// Symfony App namespace is intentionally not part of the root PSR-4 map.
require_once $root.'/symfony/src/Persistence/Federation/FederationCapabilityBindingResolver.php';

use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
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
use Platform\Orchestration\Goal\GoalPlanValidator;
use Platform\Orchestration\Goal\GoalSpecification;

$manifest=require dirname(__DIR__,2).'/app/Domains/Sales/module.php';
$catalog=new ModuleCatalog([
    new ModuleDefinition(ModuleManifest::fromArray($manifest),
        ModuleContributions::fromArray($manifest['contributions'])),
]);
$contracts=new CanonicalCapabilityCatalog($catalog);
$states=new class implements ModuleStateRepositoryInterface {
    public function enabledOverride(string $organizationId,string $moduleId):?bool{return true;}
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void{}
};
$modules=new ActiveModuleResolver($catalog,$states);
$binding=new FederationCapabilityBindingResolver($contracts,new DomainModuleRegistry([]),$modules);
$actor=new TenantContext(UserId::fromString('manager'),OrganizationId::fromString('goal-read-fixture'),
    OrganizationRole::fromString('manager'),[Permission::fromString(TenantPermissions::MANAGE)]);
$available=$binding->available($actor);
if(!in_array('sales.deal.won.observation',$available,true)){
    throw new RuntimeException('Active Sales Domain read-only capability is not eligible for Goal plans.');
}
$spec=new GoalSpecification('goal-read','goal-read-fixture','manager','Verify domain business fact',
    [['id'=>'sales.local_tasks_created','operator'=>'at_least','expected'=>1]],
    ['sales.deal.won.observation']);
$step=['id'=>'observe','capability_id'=>'sales.deal.won.observation','capability_version'=>'1.0.0',
    'depends_on'=>[],'evidence'=>[
        'kind'=>'sales.deal.won.v1','event_id'=>'event-1','deal_id'=>'deal-1',
        'reference'=>'sales:deal:won:v1:event-1:'.str_repeat('a',64),
    ]];
$result=(new GoalPlanValidator($contracts))->validate($spec,[$step],$available);
if(!$result['valid']||($result['steps'][0]['evidence']['event_id']??null)!=='event-1'
    ||($result['steps'][0]['execution_binding']??null)!=='read:sales.deal.won.v1'){
    throw new RuntimeException('Goal validator discarded independently pinned non-Action evidence.');
}
$step['evidence']['kind']='invalid';
$denied=(new GoalPlanValidator($contracts))->validate($spec,[$step],$available);
if($denied['valid'])throw new RuntimeException('Goal validator accepted mismatched evidence binding.');
echo "Federation read-only Domain capability and pinned evidence validation passed.\n";
