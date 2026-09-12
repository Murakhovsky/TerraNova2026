<?php
declare(strict_types=1);

use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\BulkModuleStateRepositoryInterface;
use Kernel\Module\Contract\ModuleLifecycleRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleInstallation;
use Kernel\Module\ModuleManifest;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class SnapshotStateRepository implements BulkModuleStateRepositoryInterface
{
    public int $bulkReads = 0;
    public int $pointReads = 0;

    /** @param array<string, bool> $overrides */
    public function __construct(private array $overrides) {}

    public function enabledOverride(string $organizationId, string $moduleId): ?bool
    {
        $this->pointReads++;
        return $this->overrides[$moduleId] ?? null;
    }

    public function enabledOverrides(string $organizationId): array
    {
        $this->bulkReads++;
        return $this->overrides;
    }

    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void
    {
        $this->overrides[$moduleId] = $enabled;
    }
}

final class SnapshotLifecycleRepository implements ModuleLifecycleRepositoryInterface
{
    public int $bulkReads = 0;
    public int $pointReads = 0;

    /** @param array<string, ModuleInstallation> $installations */
    public function __construct(private array $installations) {}

    public function find(string $organizationId, string $moduleId): ?ModuleInstallation
    {
        $this->pointReads++;
        return $this->installations[$moduleId] ?? null;
    }

    public function record(string $organizationId, ModuleManifest $manifest, string $status): void
    {
        $this->installations[$manifest->id] = new ModuleInstallation(
            $organizationId,
            $manifest->id,
            $status,
            $manifest->version,
            $manifest->schemaVersion,
        );
    }

    public function forOrganization(string $organizationId): array
    {
        $this->bulkReads++;
        return array_values($this->installations);
    }
}

$catalog = new ModuleCatalog([
    new ModuleManifest('base', 'Base', '1.0.0'),
    new ModuleManifest('sales', 'Sales', '1.0.0', dependencies: ['base']),
    new ModuleManifest('diagnostic', 'Diagnostic', '1.0.0', enabledByDefault: false),
]);
$states = new SnapshotStateRepository(['base' => false, 'diagnostic' => true]);
$lifecycle = new SnapshotLifecycleRepository([
    'base' => new ModuleInstallation('org-1', 'base', ModuleInstallation::INSTALLED, '1.0.0', '1.0.0'),
]);
$resolver = new ActiveModuleResolver($catalog, $states, $lifecycle);

$description = $resolver->describe('org-1');
if ($states->bulkReads !== 1 || $states->pointReads !== 0) {
    throw new RuntimeException('Module state snapshot must use exactly one bulk state read.');
}
if ($lifecycle->bulkReads !== 1 || $lifecycle->pointReads !== 0) {
    throw new RuntimeException('Module lifecycle snapshot must use exactly one bulk lifecycle read.');
}

$byId = [];
foreach ($description as $module) {
    $byId[$module['id']] = $module;
}
if ($byId['base']['configured_enabled'] !== false || $byId['base']['active'] !== false) {
    throw new RuntimeException('Base module override must disable the module.');
}
if ($byId['sales']['configured_enabled'] !== true || $byId['sales']['active'] !== false) {
    throw new RuntimeException('Dependent module must be inactive when a dependency is disabled.');
}
if ($byId['diagnostic']['configured_enabled'] !== true || $byId['diagnostic']['active'] !== true) {
    throw new RuntimeException('Explicit override must activate a default-disabled module.');
}
if ($byId['diagnostic']['installed'] !== true || $byId['diagnostic']['current'] !== true) {
    throw new RuntimeException('Missing lifecycle state must preserve pre-lifecycle installed/current semantics.');
}

$statesBefore = $states->bulkReads;
$lifecycleBefore = $lifecycle->bulkReads;
$one = $resolver->describeModule('org-1', 'sales');
if ($one['id'] !== 'sales' || $states->bulkReads !== $statesBefore + 1 || $lifecycle->bulkReads !== $lifecycleBefore + 1) {
    throw new RuntimeException('Single module description must be resolved from one bulk snapshot.');
}

echo "COS module runtime snapshot invariant passed.\n";
