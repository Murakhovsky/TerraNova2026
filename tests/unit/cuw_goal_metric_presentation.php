<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/symfony/src/Persistence/Federation/FederationTrustedOutcomeEvidenceResolver.php';
require $root . '/symfony/src/Persistence/Federation/FederationCapabilityBindingResolver.php';
require $root . '/symfony/src/Web/Federation/GoalMetricPresentation.php';

use App\Persistence\Federation\FederationCapabilityBindingResolver;
use App\Persistence\Federation\FederationTrustedOutcomeEvidenceResolver;
use App\Web\Federation\GoalMetricPresentation;
use Domains\Sales\Application\Contract\SalesLocalTaskOutcomeReadModelInterface;
use Domains\Sales\Application\Service\SalesLocalTaskOutcomeEvidenceProvider;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$actor = static fn(string $org, bool $manage = true) => new TenantContext(
    UserId::fromString('manager-test'), OrganizationId::fromString($org),
    OrganizationRole::fromString('manager'),
    $manage ? [Permission::fromString(TenantPermissions::MANAGE)] : [],
);
$states = new class implements ModuleStateRepositoryInterface {
    public function enabledOverride(string $organizationId, string $moduleId): ?bool {
        return $organizationId !== 'disabled';
    }
    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void {}
};
$read = new class implements SalesLocalTaskOutcomeReadModelInterface {
    public array $calls = [];
    public function createdTaskCount(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to): int {
        $this->calls[] = [$organizationId, $from->format('Y-m-d'), $to->format('Y-m-d')];
        if ($organizationId === 'broken') throw new RuntimeException('Source unavailable');
        return $organizationId === 'tenant-a' ? 3 : 0;
    }
};
$catalog = new ModuleCatalog([new ModuleManifest('sales', 'Sales', '1.0.0')]);
$modules = new ActiveModuleResolver($catalog, $states);
$bindings = new FederationCapabilityBindingResolver(new CanonicalCapabilityCatalog($catalog), new DomainModuleRegistry([]), $modules);
$resolver = new FederationTrustedOutcomeEvidenceResolver($modules, [new SalesLocalTaskOutcomeEvidenceProvider($read)]);
$now = new DateTimeImmutable('2026-11-27T12:00:00+00:00');
$id = 'sales.local_tasks_created';

$a = GoalMetricPresentation::catalog($actor('tenant-a'), $resolver, $bindings, $now);
$b = GoalMetricPresentation::catalog($actor('tenant-b'), $resolver, $bindings, $now);
$assert($a[$id]['baseline'] === 3 && $a[$id]['readiness'] === 'observed' && count($a[$id]['evidence']) === 1, 'Trusted Sales baseline did not survive.');
$assert($b[$id]['baseline'] === 0 && $b[$id]['readiness'] === 'observed', 'Measured zero was lost or confused with absence.');
$assert($read->calls === [['tenant-a','2026-10-28','2026-11-27'], ['tenant-b','2026-10-28','2026-11-27']], 'Wrong baseline tenant or historical window.');
$assert(!$a[$id]['plan_ready'], 'Catalog invented an executable capability.');
foreach (['disabled', 'broken'] as $org) {
    $row = GoalMetricPresentation::catalog($actor($org), $resolver, $bindings, $now)[$id];
    $assert($row['baseline'] === null && $row['readiness'] === 'unknown' && $row['evidence'] === [], 'Unavailable evidence became a result.');
}
$assert($a['documents.run_linked_signatures']['baseline'] === null
    && $a['documents.run_linked_signatures']['readiness'] === 'run_required', 'Run-linked criterion fell back to an unscoped number.');
$assert($a['federation.verified_proposals']['fixed_target'] === 50, 'Golden reference target changed.');
$assert(!GoalMetricPresentation::supports('fake.metric'), 'Unknown KPI accepted.');
try {
    GoalMetricPresentation::catalog($actor('tenant-a', false), $resolver, $bindings, $now);
    throw new RuntimeException('Actor without management permission accessed tenant metrics.');
} catch (DomainException) {}
$assert(GoalMetricPresentation::validDeadline('2026-11-27', $now), 'Today ought to be a valid planning date.');
foreach (['2026-11-26', '2026-02-31', '2026-12-01x', '', "\0"] as $invalid) {
    $assert(!GoalMetricPresentation::validDeadline($invalid, $now), 'Invalid deadline was accepted.');
}
echo "CUW Goal Metric Picker: real provider, tenant isolation, zero/unknown, draft-only readiness and date validation passed.\n";
