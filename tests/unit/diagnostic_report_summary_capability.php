<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Infrastructure\Capability\DiagnosticReportSummaryAdapter;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Domains\Diagnostic\Application\Contract\DiagnosticRuntimeRepositoryInterface;
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
$gateway = new CanonicalCapabilityGateway($catalog,$active,$resolver,[new DiagnosticReportSummaryAdapter($repo)]);
$manager = new TenantContext(UserId::fromString('31'),OrganizationId::fromString('tenant-a'),OrganizationRole::fromString('manager'),[Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]);
$viewer = new TenantContext(UserId::fromString('32'),OrganizationId::fromString('tenant-a'),OrganizationRole::fromString('viewer'),[Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]);
$foreign = new TenantContext(UserId::fromString('33'),OrganizationId::fromString('tenant-b'),OrganizationRole::fromString('manager'),[Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]);
$reject=static function(callable $call):void {
    try { $call(); } catch (DomainException) { return; }
    throw new RuntimeException('Unsafe report read accepted.');
};
$id=str_repeat('a',32);
$input=['session_id'=>$id];
$cap='diagnostic.report.summary.get';
$row=['organization_id'=>'tenant-a','session_id'=>$id,'status'=>'completed','state'=>['private'=>'hidden']];
$report=['report_version'=>'1','state_revision'=>'2','report'=>['private'=>'hidden']];
$repo->rows['tenant-a:'.$id]=$row;
$repo->reports['tenant-a:'.$id]=$report;
if ($gateway->describe($manager,$cap)['status']!=='read_only') throw new RuntimeException('Missing query binding.');
$expected=['session_id'=>$id,'status'=>'completed','report_version'=>1,'state_revision'=>2];
foreach (['human_api','human_ui','trusted_read'] as $origin) {
    if ($gateway->invoke($manager,$cap,$input,$origin,'','trace')!==$expected) throw new RuntimeException('Invalid or unredacted report summary.');
}
$reject(fn()=> $gateway->invoke($foreign,$cap,$input,'human_api','','trace'));
$reject(fn()=> $gateway->invoke($viewer,$cap,$input,'human_api','','trace'));
$reject(fn()=> $gateway->invoke($manager,$cap,[...$input,'organization_id'=>'tenant-b'],'human_api','','trace'));
$reject(fn()=> $gateway->invoke($manager,$cap,['session_id'=>'bad'],'human_api','','trace'));
$repo->rows['tenant-a:'.$id]=[...$row,'status'=>'active'];
$reject(fn()=> $gateway->invoke($manager,$cap,$input,'human_api','','trace'));
$repo->rows['tenant-a:'.$id]=[...$row,'organization_id'=>'tenant-b'];
$reject(fn()=> $gateway->invoke($manager,$cap,$input,'human_api','','trace'));
$repo->rows['tenant-a:'.$id]=$row;
unset($repo->reports['tenant-a:'.$id]);
$reject(fn()=> $gateway->invoke($manager,$cap,$input,'human_api','','trace'));
$repo->reports['tenant-a:'.$id]=[...$report,'state_revision'=>0];
$reject(fn()=> $gateway->invoke($manager,$cap,$input,'human_api','','trace'));
$repo->reports['tenant-a:'.$id]=$report;
$state->enabled=false;
$reject(fn()=> $gateway->invoke($manager,$cap,$input,'human_api','','trace'));
echo "Diagnostic report summary native tenant readback PASS.\n";
