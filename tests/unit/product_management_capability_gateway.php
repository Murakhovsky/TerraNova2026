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

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
spl_autoload_register(static function(string $class)use($root):void {
    if(!str_starts_with($class,'App\\'))return;
    $path=$root.'/symfony/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});
$manifest=require $root.'/app/Domains/ProductManagement/module.php';
$modulesCatalog=new ModuleCatalog([ModuleDefinition::fromArray($manifest)]);
$catalog=new CanonicalCapabilityCatalog($modulesCatalog);
$states=new class implements ModuleStateRepositoryInterface {
    public bool $enabled=true;
    public function enabledOverride(string $organizationId,string $moduleId):?bool {
        return $moduleId==='product_management'?$this->enabled:null;
    }
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void {$this->enabled=$enabled;}
};
$modules=new ActiveModuleResolver($modulesCatalog,$states);
$bindings=new FederationCapabilityBindingResolver($catalog,new DomainModuleRegistry([]),$modules);
$stock=new class implements CapabilityInvocationAdapterInterface {
    public int $calls=0;
    public function binding():string{return 'command:product_management.stock.receive';}
    public function invoke(TenantContext $actor,array $input,string $key,string $trace):mixed {
        ++$this->calls;
        return ['org'=>$actor->organizationId()->value(),'quantity'=>$input['quantity'],'key'=>$key];
    }
};
$csv=new class implements CapabilityInvocationAdapterInterface {
    public int $calls=0;
    public function binding():string{return 'command:product_management.import.preview';}
    public function invoke(TenantContext $actor,array $input,string $key,string $trace):mixed {
        ++$this->calls;
        return ['org'=>$actor->organizationId()->value(),'digest'=>hash('sha256',$input['csv']),'key'=>$key];
    }
};
$read=new class implements CapabilityInvocationAdapterInterface {
    public int $calls=0;
    public function binding():string{return 'query:product_management.category.search';}
    public function invoke(TenantContext $actor,array $input,string $key,string $trace):mixed {
        ++$this->calls;
        return ['org'=>$actor->organizationId()->value(),'limit'=>$input['limit']??30];
    }
};
$gateway=new CanonicalCapabilityGateway($catalog,$modules,$bindings,[$stock,$csv,$read]);
$actor=new TenantContext(
    UserId::fromString('121'),OrganizationId::fromString('pm-gateway-ci'),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)],
);
$viewer=new TenantContext(
    UserId::fromString('122'),OrganizationId::fromString('pm-gateway-ci'),
    OrganizationRole::fromString('viewer'),
    [Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)],
);
$assert=static function(bool $yes,string $reason):void {
    if(!$yes)throw new RuntimeException('PM Gateway: '.$reason);
};
$deny=static function(callable $fn)use($assert):void {
    try{$fn();}catch(DomainException){return;}
    $assert(false,'Untrusted Product capability invoked.');
};
$assert(count($catalog->executables())===28,'expected 28 Product Management contracts');
$assert($gateway->describe($actor,'product_management.stock.receive')['status']==='direct_human',
    'stock command must be bound only to trusted human');
$assert($gateway->describe($actor,'product_management.category.search')['status']==='read_only',
    'category query must use read-only binding');
$id=str_repeat('a',32);
$payload=['variant_id'=>$id,'location_id'=>$id,'quantity'=>'4.250000','source_ref'=>'ci-receipt'];
$result=$gateway->invoke($actor,'product_management.stock.receive',$payload,'human_api','pm-gate-1','trace-001');
$assert($result===['org'=>'pm-gateway-ci','quantity'=>'4.250000','key'=>'pm-gate-1']&&$stock->calls===1,
    'valid tenant-scoped stock command did not reach existing bus adapter');
$deny(static fn()=> $gateway->invoke($actor,'product_management.stock.receive',$payload,'cron','pm-gate-2','trace-002'));
$deny(static fn()=> $gateway->invoke($actor,'product_management.stock.receive',$payload,'ai','pm-gate-3','trace-003'));
$deny(static fn()=> $gateway->invoke($actor,'product_management.stock.receive',$payload,'human_api','','trace-004'));
$deny(static fn()=> $gateway->invoke($actor,'product_management.stock.receive',$payload+['organization_id'=>'other-tenant'],
    'human_api','pm-gate-5','trace-005'));
$deny(static fn()=> $gateway->invoke($actor,'product_management.stock.receive',
    array_replace($payload,['quantity'=>'-1']),'human_api','pm-gate-6','trace-006'));
$deny(static fn()=> $gateway->invoke($actor,'product_management.stock.receive',
    array_replace($payload,['quantity'=>'0.0000001']),'human_api','pm-gate-7','trace-007'));
$deny(static fn()=> $gateway->invoke($viewer,'product_management.stock.receive',
    $payload,'human_api','pm-gate-8','trace-008'));
$assert($stock->calls===1,'rejected stock commands must not reach a Domain handler');
$csvText="sku,name,type,uom,track_inventory\nSKU-1,Unit,stock,pcs,1\n";
$ok=$gateway->invoke($actor,'product_management.import.preview',['csv'=>$csvText],'human_api','pm-csv-1','trace-009');
$assert($ok['digest']===hash('sha256',$csvText)&&$csv->calls===1,
    'bounded CSV preview must use canonical human command invocation');
$deny(static fn()=> $gateway->invoke($actor,'product_management.import.preview',
    ['csv'=>str_repeat('a',12001)],'human_api','pm-csv-2','trace-010'));
$deny(static fn()=> $gateway->invoke($actor,'product_management.import.preview',
    ['csv'=>$csvText],'cron','pm-csv-3','trace-011'));
$assert($csv->calls===1,'rejected previews must not reach staging');
$cat=$gateway->invoke($actor,'product_management.category.search',['limit'=>20],'trusted_read','','trace-012');
$assert($cat['org']==='pm-gateway-ci'&&$cat['limit']===20&&$read->calls===1,
    'category query must retain trusted tenant scope');
$states->enabled=false;
$deny(static fn()=> $gateway->invoke($actor,'product_management.category.search',
    ['limit'=>20],'trusted_read','','trace-013'));
$deny(static fn()=> $gateway->invoke($actor,'product_management.stock.receive',
    $payload,'human_api','pm-gate-9','trace-014'));
$assert($read->calls===1&&$stock->calls===1,'inactive module must reject both reads and writes');
echo "Product Management 28 canonical Gateway contracts, tenant policy and schema guards passed.\n";
