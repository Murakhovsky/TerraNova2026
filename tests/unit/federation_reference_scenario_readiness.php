<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/symfony/src/Persistence/Federation/FederationCapabilityBindingResolver.php';
require dirname(__DIR__, 2) . '/symfony/src/Persistence/Federation/FederationReferenceScenarioReadiness.php';

use App\Persistence\Federation\FederationCapabilityBindingResolver;
use App\Persistence\Federation\FederationReferenceScenarioReadiness;
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

$catalog = new ModuleCatalog([
    new ModuleDefinition(new ModuleManifest('growth', 'Growth', '1.0.0'), ModuleContributions::fromArray([
        'capabilities' => [
            'growth.market.discovery', 'growth.candidate.qualify', 'growth.handoff.prepare', 'growth.handoff.target.sales',
        ],
    ])),
    new ModuleDefinition(new ModuleManifest('sales', 'Sales', '1.0.0'), new ModuleContributions()),
    new ModuleDefinition(new ModuleManifest('documents', 'Documents', '1.0.0'), new ModuleContributions()),
]);
$canonical = new CanonicalCapabilityCatalog($catalog);
$states = new class implements ModuleStateRepositoryInterface {
    public function enabledOverride(string $organizationId, string $moduleId): ?bool { return true; }
    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void {}
};
$binding = new FederationCapabilityBindingResolver(
    $canonical, new DomainModuleRegistry([]), new ActiveModuleResolver($catalog, $states),
);
$gate = new FederationReferenceScenarioReadiness($canonical, $binding);
$actor = static fn (string $role, array $grants): TenantContext => new TenantContext(
    UserId::fromString('manager-1'), OrganizationId::fromString('tenant-one'),
    OrganizationRole::fromString($role),
    array_map(static fn (string $s): Permission => Permission::fromString($s), $grants),
);
$report = $gate->inspect($actor('manager', [TenantPermissions::ACCESS, TenantPermissions::MANAGE]));
$statuses = array_column($report['stages'], 'status', 'stage');
if ($report['execution_ready'] !== false || $report['business_outcome_verified'] !== false
    || count($report['stages']) !== 5
    || $statuses['discover_candidates'] !== 'manifest_only'
    || $statuses['qualify_candidates'] !== 'manifest_only'
    || $statuses['prepare_handoff'] !== 'manifest_only'
    || $statuses['handoff_and_sales_intake'] !== 'manifest_only'
    || $statuses['prepare_proposals'] !== 'missing_capability') {
    throw new RuntimeException('A manifest-only capability falsely passed the golden-path gate.');
}
foreach ([
    $actor('viewer', [TenantPermissions::ACCESS, TenantPermissions::MANAGE]),
    $actor('manager', [TenantPermissions::ACCESS]),
] as $unauthorized) {
    try {
        $gate->inspect($unauthorized);
        throw new RuntimeException('Non-authorized actor viewed readiness.');
    } catch (DomainException) {}
}
echo "Package A readiness: manifest-only is not executable; tenant manager gate passed.\n";
