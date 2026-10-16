<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDefinition;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

$root = dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

$manifest=require $root.'/app/Domains/Property/module.php';
$catalog=new CanonicalCapabilityCatalog(new ModuleCatalog([ModuleDefinition::fromArray($manifest)]));
$state=new class implements ModuleStateRepositoryInterface {
    public bool $enabled=true;
    public function enabledOverride(string $organizationId,string $moduleId):?bool {
        return $this->enabled;
    }
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void {
        $this->enabled=$enabled;
    }
};
$modules=new ActiveModuleResolver(new ModuleCatalog([ModuleDefinition::fromArray($manifest)]),$state);
$bindings=new FederationCapabilityBindingResolver($catalog,new DomainModuleRegistry([]),$modules);
$writes=new class implements CapabilityInvocationAdapterInterface {
    public int $calls=0;
    public function binding():string { return 'command:property.asset.create'; }
    public function invoke(TenantContext $actor,array $input,string $key,string $trace):mixed {
        $this->calls++;
        return ['tenant'=>$actor->organizationId()->value(),'key'=>$key,'city'=>$input['city']];
    }
};
$reads=new class implements CapabilityInvocationAdapterInterface {
    public int $calls=0;
    public function binding():string { return 'query:property.asset.get'; }
    public function invoke(TenantContext $actor,array $input,string $key,string $trace):mixed {
        $this->calls++;
        return ['tenant'=>$actor->organizationId()->value(),'reference'=>$input['reference']];
    }
};
$gateway=new CanonicalCapabilityGateway($catalog,$modules,$bindings,[$writes,$reads]);
$manager=new TenantContext(
    UserId::fromString('15'),OrganizationId::fromString('cos-test-tenant'),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)],
);
$viewer=new TenantContext(
    UserId::fromString('16'),OrganizationId::fromString('cos-test-tenant'),
    OrganizationRole::fromString('viewer'),
    [Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)],
);
$check=static function(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); };
$reject=static function(callable $fn) use($check):void {
    try { $fn(); } catch (DomainException) { return; }
    $check(false,'Gateway should have denied invocation.');
};

$check($gateway->describe($manager,'property.asset.create')['status']==='direct_human','Missing live human binding.');
$check($gateway->describe($manager,'property.asset.get')['status']==='read_only','Missing read-only binding.');
$check($gateway->describe($manager,'property.asset.search')['status']==='unavailable','Unbound query must be unavailable.');
$answer=$gateway->invoke($manager,'property.asset.create',
    ['type_code'=>'house','city'=>'Львів'],'human_ui','fixed-key','trace-123');
$check($answer===['tenant'=>'cos-test-tenant','key'=>'fixed-key','city'=>'Львів'] && $writes->calls===1,
    'Valid canonical human write was not routed correctly.');
$reject(static fn()=>$gateway->invoke($manager,'property.asset.create',
    ['type_code'=>'house','city'=>'Львів'],'cron','key-2','trace-123'));
$reject(static fn()=>$gateway->invoke($manager,'property.asset.create',
    ['type_code'=>'house','city'=>'Львів'],'ai','key-2','trace-123'));
$reject(static fn()=>$gateway->invoke($manager,'property.asset.create',
    ['type_code'=>'house','city'=>'Львів'],'human_api','','trace-123'));
$reject(static fn()=>$gateway->invoke($manager,'property.asset.create',
    ['type_code'=>'house','city'=>'Львів','organization_id'=>'forged'],'human_ui','key-2','trace-123'));
$reject(static fn()=>$gateway->invoke($manager,'property.asset.create',
    ['type_code'=>'house','city'=>'Львів','area_total'=>'string'],'human_ui','key-2','trace-123'));
$check($writes->calls===1,'Rejected calls reached a Domain mutator.');
$got=$gateway->invoke($manager,'property.asset.get',['reference'=>'PROP-123'],'trusted_read','','trace-456');
$check($got===['tenant'=>'cos-test-tenant','reference'=>'PROP-123'] && $reads->calls===1,
    'Read-only query was not routed to tenant-scoped Domain.');
$reject(static fn()=>$gateway->invoke($manager,'property.asset.get',['reference'=>'PROP-123','tenant_id'=>'other'],
    'trusted_read','','trace-456'));
$reject(static fn()=>$gateway->invoke($viewer,'property.asset.get',['reference'=>'PROP-123'],'human_ui','','trace-456'));
$state->enabled=false;
$reject(static fn()=>$gateway->invoke($manager,'property.asset.get',['reference'=>'PROP-123'],'human_ui','','trace-456'));
$check($reads->calls===1,'Unauthorized read reached Domain.');
try {
    new CanonicalCapabilityGateway($catalog,$modules,$bindings,[$reads,$reads]);
    throw new RuntimeException('Duplicate binding accepted.');
} catch (LogicException) {}

echo "Canonical Capability Gateway tenant/policy/input dispatch PASS.\n";
