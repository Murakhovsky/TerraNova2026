<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Database\MigrationRunnerInterface;
use Kernel\Module\Contract\ModuleConfigurationProvisionerInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;
use Kernel\Module\ModuleTenantProvisioner;

$configuration = new class implements ModuleConfigurationProvisionerInterface {
    /** @var list<array{organization_id: string, actor_id: string}> */
    public array $calls = [];

    public function provision(string $organizationId, string $actorId): array
    {
        $this->calls[] = ['organization_id' => $organizationId, 'actor_id' => $actorId];

        return [
            'domains' => 1,
            'rules' => 2,
            'policies' => 3,
            'manifest_hashes' => ['sales' => 'manifest-hash'],
        ];
    }
};

$migrations = new class implements MigrationRunnerInterface {
    /** @var list<array{migration: string, applied_at: string}> */
    public array $rows = [];
    public function migrate(): array { return ['applied' => [], 'skipped' => []]; }
    public function status(): array { return $this->rows; }
};

$catalog = new ModuleCatalog([
    new ModuleDefinition(
        new ModuleManifest('sales', 'Sales', '1.0.0'),
        new ModuleContributions(
            runtimeModuleService: 'salesDomainModule',
            migrationFiles: ['app/migrations/20260910_000030_sales_v071_configuration_ownership.sql'],
            configurationProvisionerServices: ['salesModuleConfigurationProvisioner'],
        ),
    ),
]);

$provisioner = new ModuleTenantProvisioner($catalog, $migrations, [[
    'module_id' => 'sales',
    'service' => $configuration,
]]);

$blocked = false;
try {
    $provisioner->provision('org-a', 'sales', '42');
} catch (RuntimeException $error) {
    $blocked = str_contains($error->getMessage(), 'schema is not ready')
        && str_contains($error->getMessage(), '20260910_000030_sales_v071_configuration_ownership');
}
if (!$blocked) throw new RuntimeException('Tenant provisioning did not reject an unapplied deployment migration.');
if ($configuration->calls !== []) throw new RuntimeException('Configuration was provisioned before schema readiness passed.');

$migrations->rows = [[
    'migration' => '20260910_000030_sales_v071_configuration_ownership',
    'applied_at' => '2026-09-11 00:00:00',
]];
$result = $provisioner->provision('org-a', 'sales', '42');

if (($result['modules'] ?? []) !== ['sales']) throw new RuntimeException('Provisioning result does not report the module.');
if (($result['domains'] ?? 0) !== 1 || ($result['rules'] ?? 0) !== 2 || ($result['policies'] ?? 0) !== 3) {
    throw new RuntimeException('Declared configuration provisioner result was not merged.');
}
if (($result['manifest_hashes']['sales'] ?? null) !== 'manifest-hash') {
    throw new RuntimeException('Declared configuration provisioner manifest hash was not preserved.');
}
if (count($configuration->calls) !== 1) throw new RuntimeException('Declared configuration provisioner was not called exactly once.');
if (($configuration->calls[0]['organization_id'] ?? null) !== 'org-a' || ($configuration->calls[0]['actor_id'] ?? null) !== '42') {
    throw new RuntimeException('Tenant provisioning lost organization or actor ownership.');
}

// A runtime module alone must not imply tenant configuration ownership.
$withoutDeclaredProvisioner = new ModuleTenantProvisioner($catalog, $migrations);
$empty = $withoutDeclaredProvisioner->provision('org-b', 'sales', '7');
if (($empty['domains'] ?? -1) !== 0 || ($empty['rules'] ?? -1) !== 0 || ($empty['policies'] ?? -1) !== 0) {
    throw new RuntimeException('Runtime module service still implicitly triggers tenant configuration provisioning.');
}
if (count($configuration->calls) !== 1) {
    throw new RuntimeException('Undeclared configuration provisioner was invoked.');
}

echo "Module tenant provisioning invariants passed.\n";
