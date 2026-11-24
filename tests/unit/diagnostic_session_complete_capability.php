<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Application\Diagnostic\Command\CompleteDiagnosticCommand;
use App\Infrastructure\Capability\DiagnosticSessionCompleteAdapter;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Kernel\Application\Bus\CommandBusInterface;
use Domains\Diagnostic\Application\Contract\DiagnosticRuntimeRepositoryInterface;
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
spl_autoload_register(static function (string $name) use ($root): void {
    if (!str_starts_with($name, 'App\\')) return;
    $file = $root . '/symfony/src/' . str_replace('\\', '/', substr($name, 4)) . '.php';
    if (is_file($file)) require $file;
});
$module = require $root . '/app/Domains/Diagnostic/module.php';
$moduleCatalog = new ModuleCatalog([ModuleDefinition::fromArray($module)]);
$catalog = new CanonicalCapabilityCatalog($moduleCatalog);
$state = new class implements ModuleStateRepositoryInterface {
    public bool $enabled = true;
    public function enabledOverride(string $org, string $module): ?bool { return $this->enabled; }
    public function setEnabled(string $org, string $module, bool $enabled): void { $this->enabled = $enabled; }
};
$active = new ActiveModuleResolver($moduleCatalog, $state);
$resolver = new FederationCapabilityBindingResolver($catalog, new DomainModuleRegistry([]), $active);
$repo = new class implements DiagnosticRuntimeRepositoryInterface {
    public array $rows = [];
    public array $reports = [];
    public function get(string $organizationId, string $sessionId): ?array {
        return $this->rows[$organizationId . ':' . $sessionId] ?? null;
    }
    public function report(string $organizationId, string $sessionId): ?array {
        return $this->reports[$organizationId . ':' . $sessionId] ?? null;
    }
    public function create(string $organizationId, string $sessionId, string $mode, array $state, ?string $parentSessionId, DateTimeImmutable $at): void { throw new LogicException('Unexpected native mutation.'); }
    public function saveState(string $organizationId, string $sessionId, array $state, ?string $currentQuestionId, int $revision): void { throw new LogicException('Unexpected native mutation.'); }
    public function complete(string $organizationId, string $sessionId, DateTimeImmutable $at): void { throw new LogicException('Unexpected native mutation.'); }
    public function saveReport(string $organizationId, string $sessionId, array $report, int $stateRevision, DateTimeImmutable $at): int { throw new LogicException('Unexpected native mutation.'); }
    public function saveRecommendation(string $organizationId, string $sessionId, string $recommendationId, array $payload, string $status, DateTimeImmutable $at, ?string $actionId = null): void { throw new LogicException('Unexpected native mutation.'); }
    public function recommendation(string $organizationId, string $sessionId, string $recommendationId): ?array { throw new LogicException('Unexpected native mutation.'); }
    public function recommendations(string $organizationId, string $sessionId): array { throw new LogicException('Unexpected native mutation.'); }
    public function appendMeasurement(string $organizationId, string $sessionId, string $actionId, string $metricCode, float $value, ?string $evidenceId, DateTimeImmutable $at): void { throw new LogicException('Unexpected native mutation.'); }
    public function measurements(string $organizationId, string $sessionId): array { throw new LogicException('Unexpected native mutation.'); }
    public function schedule(string $organizationId, string $sourceSessionId, int $intervalDays, DateTimeImmutable $dueAt, DateTimeImmutable $at): array { throw new LogicException('Unexpected native mutation.'); }
    public function dueSchedules(string $organizationId, DateTimeImmutable $at, int $limit = 100): array { throw new LogicException('Unexpected native mutation.'); }
    public function pendingScheduleForSource(string $organizationId, string $sourceSessionId): ?array { throw new LogicException('Unexpected native mutation.'); }
    public function markScheduleStarted(string $organizationId, string $scheduleId, string $followupSessionId, DateTimeImmutable $at): void { throw new LogicException('Unexpected native mutation.'); }
};
$bus = new class($repo) implements CommandBusInterface {
    public array $calls = [];
    public bool $badReceipt = false;
    public bool $skipWrite = false;
    public function __construct(private object $repo) {}
    public function dispatch(CommandInterface $command): mixed {
        if (!$command instanceof CompleteDiagnosticCommand) throw new LogicException('Wrong command.');
        $this->calls[] = $command;
        $id = $command->organizationId->value().':'.$command->sessionId;
        $row = $this->repo->rows[$id];
        $replay = $row['status'] === 'completed';
        if (!$replay && !$this->skipWrite) {
            $this->repo->rows[$id]['status'] = 'completed';
            $this->repo->reports[$id] = ['report_version'=>1,'report'=>['private'=>'not public']];
        }
        return ['report_version'=>$this->badReceipt ? 99 : 1,'replayed'=>$replay,'report'=>['secret'=>'do not return']];
    }
};
$gateway = new CanonicalCapabilityGateway($catalog, $active, $resolver, [new DiagnosticSessionCompleteAdapter($bus,$repo)]);
$manager = new TenantContext(UserId::fromString('31'), OrganizationId::fromString('tenant-a'),
    OrganizationRole::fromString('manager'), [Permission::fromString(TenantPermissions::ACCESS), Permission::fromString(TenantPermissions::MANAGE)]);
$viewer = new TenantContext(UserId::fromString('32'), OrganizationId::fromString('tenant-a'),
    OrganizationRole::fromString('viewer'), [Permission::fromString(TenantPermissions::ACCESS), Permission::fromString(TenantPermissions::MANAGE)]);
$other = new TenantContext(UserId::fromString('33'), OrganizationId::fromString('tenant-b'),
    OrganizationRole::fromString('manager'), [Permission::fromString(TenantPermissions::ACCESS), Permission::fromString(TenantPermissions::MANAGE)]);
$check = static function (bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); };
$reject = static function (callable $call): void {
    try { $call(); } catch (DomainException) { return; }
    throw new RuntimeException('Unsafe Diagnostic completion was not rejected.');
};
$id = str_repeat('a',32);
$input = ['session_id'=>$id];
$repo->rows['tenant-a:'.$id] = ['organization_id'=>'tenant-a','session_id'=>$id,'status'=>'active'];
$cap = 'diagnostic.session.complete';
$check($gateway->describe($manager,$cap)['status']==='direct_human','Not human-only.');
$reject(fn()=> $gateway->invoke($other,$cap,$input,'human_api','key','trace'));
$reject(fn()=> $gateway->invoke($viewer,$cap,$input,'human_api','key','trace'));
$reject(fn()=> $gateway->invoke($manager,$cap,$input,'ai','key','trace'));
$reject(fn()=> $gateway->invoke($manager,$cap,$input,'cron','key','trace'));
$reject(fn()=> $gateway->invoke($manager,$cap,$input,'human_api','','trace'));
$reject(fn()=> $gateway->invoke($manager,$cap,[...$input,'tenant_id'=>'tenant-b'],'human_api','key','trace'));
$reject(fn()=> $gateway->invoke($manager,$cap,['session_id'=>'invalid'],'human_api','key','trace'));
$check(count($bus->calls)===0,'Denied requests reached CommandBus.');
$first=$gateway->invoke($manager,$cap,$input,'human_api','key','trace');
$check($first===['session_id'=>$id,'status'=>'completed','report_version'=>1,'replayed'=>false],'Native receipt mismatch or leakage.');
$check($bus->calls[0]->actorId===31 && $bus->calls[0]->organizationId->value()==='tenant-a' && $bus->calls[0]->correlationId==='trace','Actor scope lost.');
$again=$gateway->invoke($manager,$cap,$input,'human_ui','different-key','trace-2');
$check($again===[...$first,'replayed'=>true] && count($repo->reports)===1,'Terminal replay mismatch.');
$bus->badReceipt=true;
$reject(fn()=> $gateway->invoke($manager,$cap,$input,'human_api','key','trace'));
$bus->badReceipt=false;
$id2=str_repeat('b',32);
$repo->rows['tenant-a:'.$id2]=['organization_id'=>'tenant-a','session_id'=>$id2,'status'=>'active'];
$bus->skipWrite=true;
$reject(fn()=> $gateway->invoke($manager,$cap,['session_id'=>$id2],'human_api','key','trace'));
$state->enabled=false;
$reject(fn()=> $gateway->invoke($manager,$cap,$input,'human_api','key','trace'));
$check(count($bus->calls)===4,'Disabled or denied invocation reached bus.');
echo "Diagnostic session.complete canonical Gateway and native readback PASS.\n";
