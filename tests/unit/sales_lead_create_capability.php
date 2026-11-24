<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Application\Sales\Command\CreateSalesLeadCommand;
use App\Application\Sales\Command\SalesMutationResult;
use App\Infrastructure\Capability\SalesLeadCreateAdapter;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Domains\Sales\Application\Contract\SalesProposalLeadReadModelInterface;
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
$read = new class implements SalesProposalLeadReadModelInterface {
    public array $leadsById = [];
    public array $casesById = [];
    public function dashboard(string $organizationId, ?int $ownerId = null): array { return []; }
    public function leads(string $organizationId, array $filters = []): array { return []; }
    public function lead(string $organizationId, int $leadId): ?array { return $this->leadsById[$organizationId.':'.$leadId] ?? null; }
    public function deals(string $organizationId, array $filters = []): array { return []; }
    public function deal(string $organizationId, int $dealId): ?array { return $this->casesById[$organizationId.':'.$dealId] ?? null; }
    public function timeline(string $organizationId, int $dealId, int $limit = 100): array { return []; }
    public function pipelines(string $organizationId): array { return []; }
    public function today(string $organizationId, int $ownerId): array { return []; }
    public function metrics(string $organizationId, int $days = 30): array { return []; }
};
$bus = new class($read) implements CommandBusInterface {
    public array $calls = [];
    public bool $skipWrite = false;
    public bool $failure = false;
    public bool $wrongReceipt = false;
    public int $mutations = 0;
    public function __construct(private object $read) {}
    public function dispatch(CommandInterface $command): mixed {
        if (!$command instanceof CreateSalesLeadCommand) throw new LogicException('Unexpected command.');
        $this->calls[] = $command;
        if ($this->failure) return SalesMutationResult::failure('invalid_owner', 'Invalid owner');
        $org = $command->organizationId->value();
        $key = $org.':51';
        $replayed = isset($this->read->leadsById[$key]);
        if (!$replayed && !$this->skipWrite) {
            $this->mutations++;
            $this->read->leadsById[$key] = ['id'=>'51','organization_id'=>$org,'status'=>'new','assigned_user_id'=>31,
                'full_name'=>$command->input['full_name'],'email'=>$command->input['email'],'phone'=>null];
        }
        return SalesMutationResult::success($replayed?'duplicate':'created',['lead_id'=>$this->wrongReceipt?52:51,'private'=>'hidden']);
    }
};
$gateway = new CanonicalCapabilityGateway($catalog, $active, $resolver, [new SalesLeadCreateAdapter($bus, $read)]);
$actor = static fn(string $org, string $role='manager', bool $manage=true) => new TenantContext(
    UserId::fromString('31'), OrganizationId::fromString($org), OrganizationRole::fromString($role),
    $manage ? [Permission::fromString(TenantPermissions::ACCESS), Permission::fromString(TenantPermissions::MANAGE)] : [Permission::fromString(TenantPermissions::ACCESS)],
);
$manager = $actor('tenant-a');
$cap = 'sales.lead.create';
$input = ['full_name'=>'Example Lead','email'=>'example@example.test'];
$call = static fn(array $data, string $origin='human_api', string $key='convert-key') => $gateway->invoke($manager,$cap,$data,$origin,$key,'trace');
$check = static function(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); };
$reject = static function(callable $fn): void { try { $fn(); } catch (DomainException) { return; } throw new RuntimeException('Unsafe Sales conversion accepted.'); };
$check($gateway->describe($manager,$cap)['status']==='direct_human','Expected canonical human command.');
$reject(fn()=>$gateway->invoke($actor('tenant-a','viewer'),$cap,$input,'human_api','key','trace'));
$reject(fn()=>$gateway->invoke($actor('tenant-a','manager',false),$cap,$input,'human_api','key','trace'));
foreach (['ai','cron','trusted_read'] as $origin) $reject(fn()=>$call($input,$origin));
$reject(fn()=>$call($input,'human_api',''));
$reject(fn()=>$call(['full_name'=>'No contact']));
$reject(fn()=>$call(['full_name'=>'Name','email'=>'invalid']));
$reject(fn()=>$call(['full_name'=>' ','email'=>'example@example.test']));
$reject(fn()=>$call([...$input,'tenant_id'=>'tenant-b']));
$reject(fn()=>$call([...$input,'owner_id'=>99]));
$check(count($bus->calls)===0,'Denied requests reached writer.');
$bus->failure=true;
$reject(fn()=>$call($input));
$bus->failure=false;
$bus->skipWrite=true;
$reject(fn()=>$call($input));
$bus->skipWrite=false;
$expected=['lead_id'=>51,'replayed'=>false];
$check($call($input)===$expected,'Conversion receipt did not match native state.');
$check($call($input,'human_ui','convert-key')===[...$expected,'replayed'=>true],'Natural replay must return existing case.');
$check($bus->mutations===1,'Replay created another case.');
$command=$bus->calls[2];
$check($command->actorId===31 && $command->organizationId->value()==='tenant-a' && $command->input===[...$input,'source'=>'manager-api'] && $command->idempotencyKey==='convert-key' && $command->correlationId==='trace','Actor or command authority was not server-derived.');
$bus->wrongReceipt=true;
$reject(fn()=>$call($input));
$bus->wrongReceipt=false;
$read->leadsById['tenant-a:51']['organization_id']='tenant-b';
$reject(fn()=>$call($input));
$state->enabled=false;
$reject(fn()=>$call($input));
echo "Sales authenticated intake capability and native readback PASS.\n";
