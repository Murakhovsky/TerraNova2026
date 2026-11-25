<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Application\Integration\Command\RecoverCrmInboxCommand;
use App\Application\Integration\Command\ProcessCrmInboxNowCommand;
use App\Infrastructure\Capability\SalesCrmInboxProcessAdapter;
use App\Infrastructure\Capability\SalesCrmInboxStatusAdapter;
use App\Infrastructure\Capability\SalesCrmInboxRecoverAdapter;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Domains\Sales\Application\Contract\CrmInboxRecoveryRepositoryInterface;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Command\CommandInterface;
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

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
spl_autoload_register(static function(string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) return;
    $candidate = $root . '/symfony/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($candidate)) require $candidate;
});
$modules = new ModuleCatalog([ModuleDefinition::fromArray(require $root . '/app/Domains/Sales/module.php')]);
$catalog = new CanonicalCapabilityCatalog($modules);
$state = new class implements ModuleStateRepositoryInterface {
    public bool $enabled = true;
    public function enabledOverride(string $organizationId, string $moduleId): ?bool { return $this->enabled; }
    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void { $this->enabled = $enabled; }
};
$active = new ActiveModuleResolver($modules, $state);
$resolver = new FederationCapabilityBindingResolver($catalog, new DomainModuleRegistry([]), $active);
$id=str_repeat('a',32);$revision=str_repeat('b',64);
$read=new class($id,$revision) implements CrmInboxRecoveryRepositoryInterface {
    public array $row;
    public function __construct(string $id,string $revision) {
        $this->row=['id'=>$id,'organization_id'=>'tenant-a','status'=>'DEAD','attempts'=>10,
            'signature_verified'=>true,'revision'=>$revision,'provider'=>'aida','event_type'=>'deal.updated','payload'=>'private'];
    }
    public function status(string $organizationId,string $id,bool $lock=false):?array {
        return $organizationId==='tenant-a'?$this->row:null;
    }
    public function requeueDead(string $organizationId,string $id,string $revision):bool {throw new LogicException('Read adapter may not mutate.');}
};
$bus=new class implements CommandBusInterface {
    public array $calls=[];public bool $bad=false;
    public function dispatch(CommandInterface $command):mixed {
        if ($command instanceof ProcessCrmInboxNowCommand) {
            $this->calls[]=$command;
            return ['inbox_id'=>$command->inboxId,'status'=>$this->bad?'PROCESSING':'COMPLETED','replayed'=>false];
        }
        if (!$command instanceof RecoverCrmInboxCommand) throw new LogicException('Unexpected command.');
        $this->calls[]=$command;
        return ['inbox_id'=>$this->bad?str_repeat('f',32):$command->inboxId,'requeued'=>true,'replayed'=>false,'payload'=>'private'];
    }
};
$gateway=new CanonicalCapabilityGateway($catalog,$active,$resolver,[new SalesCrmInboxStatusAdapter($read),new SalesCrmInboxRecoverAdapter($bus),new SalesCrmInboxProcessAdapter($bus)]);
$actor=static fn(string $org,string $role='manager',bool $manage=true)=>new TenantContext(
    UserId::fromString('31'),OrganizationId::fromString($org),OrganizationRole::fromString($role),
    $manage?[Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]:[Permission::fromString(TenantPermissions::ACCESS)],
);
$manager=$actor('tenant-a');
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$reject=static function(callable $fn):void{try{$fn();}catch(DomainException){return;}throw new RuntimeException('Unsafe CRM capability accepted.');};
$status='sales.crm.inbox.status';$recover='sales.crm.inbox.recover';
$input=['inbox_id'=>$id,'revision'=>$revision,'reason'=>'mapping_repaired'];
$observation=$gateway->invoke($manager,$status,['inbox_id'=>$id],'trusted_read','','trace');
$check($observation['found'] && $observation['item']['revision']===$revision && !isset($observation['item']['payload']),'Status was not redacted.');
$check($gateway->invoke($actor('tenant-b'),$status,['inbox_id'=>$id],'human_api','','trace')===['found'=>false,'item'=>null],'Foreign inbox leaked.');
foreach(['ai','cron','trusted_read'] as $origin)$reject(fn()=>$gateway->invoke($manager,$recover,$input,$origin,'key','trace'));
$reject(fn()=>$gateway->invoke($actor('tenant-a','viewer'),$recover,$input,'human_api','key','trace'));
$reject(fn()=>$gateway->invoke($actor('tenant-a','manager',false),$recover,$input,'human_api','key','trace'));
$reject(fn()=>$gateway->invoke($manager,$recover,[...$input,'organization_id'=>'tenant-b'],'human_api','key','trace'));
$reject(fn()=>$gateway->invoke($manager,$recover,[...$input,'revision'=>'bad'],'human_api','key','trace'));
$reject(fn()=>$gateway->invoke($manager,$recover,[...$input,'reason'=>'arbitrary'],'human_api','key','trace'));
$reject(fn()=>$gateway->invoke($manager,$recover,$input,'human_api','','trace'));
$check($bus->calls===[],'Denied requests reached the writer.');
$result=$gateway->invoke($manager,$recover,$input,'human_ui','key','trace');
$check($result===['inbox_id'=>$id,'requeued'=>true,'replayed'=>false],'Wrong recovery receipt.');
$command=$bus->calls[0];
$check($command->organizationId->value()==='tenant-a' && $command->actorId===31 && $command->revision===$revision && $command->idempotencyKey==='key','Recovery identity not server derived.');
$process=['inbox_id'=>$id,'revision'=>$revision];
$reject(fn()=>$gateway->invoke($manager,'sales.crm.inbox.process',$process,'ai','key','trace'));
$check($gateway->invoke($manager,'sales.crm.inbox.process',$process,'human_api','key','trace')===['inbox_id'=>$id,'status'=>'COMPLETED','replayed'=>false],'Processing receipt invalid.');
$bus->bad=true;
$reject(fn()=>$gateway->invoke($manager,'sales.crm.inbox.process',$process,'human_api','key','trace'));
$reject(fn()=>$gateway->invoke($manager,$recover,$input,'human_api','key','trace'));
$read->row['organization_id']='tenant-b';
$reject(fn()=>$gateway->invoke($manager,$status,['inbox_id'=>$id],'trusted_read','','trace'));
$state->enabled=false;
$reject(fn()=>$gateway->invoke($manager,$recover,$input,'human_api','key','trace'));
echo "Sales CRM Inbox status and recovery Gateway PASS.\n";
