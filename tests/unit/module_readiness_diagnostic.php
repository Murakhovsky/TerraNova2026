<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Database\MigrationRunnerInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleLifecycleRepositoryInterface;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleInstallation;
use Kernel\Module\ModuleManifest;
use Kernel\Module\ModuleReadinessDiagnostic;

$states = new class implements ModuleStateRepositoryInterface {
    /** @var array<string, bool> */
    public array $values = [];
    public function enabledOverride(string $organizationId, string $moduleId): ?bool { return $this->values[$organizationId . ':' . $moduleId] ?? null; }
    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void { $this->values[$organizationId . ':' . $moduleId] = $enabled; }
};

$installations = new class implements ModuleLifecycleRepositoryInterface {
    /** @var array<string, ModuleInstallation> */
    public array $values = [];
    public function find(string $organizationId, string $moduleId): ?ModuleInstallation { return $this->values[$organizationId . ':' . $moduleId] ?? null; }
    public function forOrganization(string $organizationId): array { return array_values(array_filter($this->values, static fn (ModuleInstallation $i): bool => $i->organizationId === $organizationId)); }
    public function record(string $organizationId, ModuleManifest $manifest, string $status): void {
        $this->values[$organizationId . ':' . $manifest->id] = new ModuleInstallation($organizationId, $manifest->id, $status, $manifest->version, $manifest->schemaVersion);
    }
};

$migrations = new class implements MigrationRunnerInterface {
    /** @var list<array{migration: string, applied_at: string}> */
    public array $rows = [];
    public bool $fail = false;
    public function migrate(): array { return ['applied' => [], 'skipped' => []]; }
    public function status(): array {
        if ($this->fail) throw new RuntimeException('migration status unavailable');
        return $this->rows;
    }
};

$catalog = new ModuleCatalog([
    new ModuleDefinition(
        new ModuleManifest('core', 'Core', '2.0.0', schemaVersion: '2.0.0'),
        new ModuleContributions(migrationFiles: ['20260911_000100_core.sql']),
    ),
    new ModuleDefinition(
        new ModuleManifest('sales', 'Sales', '2.0.0', dependencies: ['core'], schemaVersion: '2.0.0'),
        new ModuleContributions(migrationFiles: ['20260911_000101_sales.sql']),
    ),
]);
$resolver = new ActiveModuleResolver($catalog, $states, $installations);
$diagnostic = new ModuleReadinessDiagnostic($catalog, $resolver, $migrations);
$migrations->rows = [
    ['migration' => '20260911_000100_core', 'applied_at' => '2026-09-11 00:00:00'],
    ['migration' => '20260911_000101_sales', 'applied_at' => '2026-09-11 00:00:00'],
];

/** @return array<string, mixed> */
$get = static function (array $snapshot, string $id): array {
    foreach ($snapshot['modules'] as $module) if (($module['id'] ?? null) === $id) return $module;
    throw new RuntimeException('Missing readiness module: ' . $id);
};

$snapshot = $diagnostic->diagnose('legacy');
if (($get($snapshot, 'sales')['status'] ?? null) !== 'READY') throw new RuntimeException('Legacy current module must diagnose READY.');

$states->setEnabled('org-disabled', 'sales', false);
$snapshot = $diagnostic->diagnose('org-disabled');
if (($get($snapshot, 'sales')['status'] ?? null) !== 'DISABLED') throw new RuntimeException('Disabled module status is incorrect.');

$installations->values['org-stale:core'] = new ModuleInstallation('org-stale', 'core', ModuleInstallation::INSTALLED, '2.0.0', '2.0.0');
$installations->values['org-stale:sales'] = new ModuleInstallation('org-stale', 'sales', ModuleInstallation::INSTALLED, '1.0.0', '1.0.0');
$snapshot = $diagnostic->diagnose('org-stale');
if (($get($snapshot, 'sales')['status'] ?? null) !== 'UPGRADE_REQUIRED') throw new RuntimeException('Stale module status is incorrect.');

$migrations->rows = [['migration' => '20260911_000100_core', 'applied_at' => '2026-09-11 00:00:00']];
$snapshot = $diagnostic->diagnose('legacy');
$sales = $get($snapshot, 'sales');
if (($sales['status'] ?? null) !== 'SCHEMA_NOT_READY' || ($sales['missing_migrations'] ?? []) !== ['20260911_000101_sales']) {
    throw new RuntimeException('Missing schema migration was not diagnosed.');
}

$migrations->rows[] = ['migration' => '20260911_000101_sales', 'applied_at' => '2026-09-11 00:00:00'];
$states->setEnabled('org-dep', 'core', false);
$snapshot = $diagnostic->diagnose('org-dep');
$sales = $get($snapshot, 'sales');
if (($sales['status'] ?? null) !== 'DEPENDENCY_NOT_READY' || ($sales['unavailable_dependencies'] ?? []) !== ['core']) {
    throw new RuntimeException('Unavailable dependency was not diagnosed.');
}

$migrations->fail = true;
$snapshot = $diagnostic->diagnose('legacy');
if (($snapshot['schema_status'] ?? null) !== 'UNAVAILABLE' || ($get($snapshot, 'sales')['status'] ?? null) !== 'SCHEMA_STATUS_UNAVAILABLE') {
    throw new RuntimeException('Migration status failure was not diagnosed safely.');
}

echo "Module readiness diagnostic invariants passed.\n";
