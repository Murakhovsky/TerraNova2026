<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Configuration\Contract\ConfigurationStoreInterface;
use Kernel\Configuration\Service\ConfigurationProvisioner;
use Kernel\Configuration\Service\ConfigurationValidator;
use Kernel\Database\MigrationRunnerInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;
use Kernel\Module\ModuleTenantProvisioner;
use Kernel\Rule\Contract\RuleContextProviderInterface;

$domain = new class implements DomainModuleInterface {
    public function name(): string { return 'sales'; }
    public function eventTypes(): array { return []; }
    public function actionTypes(): array { return []; }
    public function actionHandlers(): array { return []; }
    public function agents(): array { return []; }
    public function agentContextBuilders(): array { return []; }
    public function ruleContextProvider(): RuleContextProviderInterface { throw new RuntimeException('Not used by provisioning test.'); }
    public function rules(string $organizationId): array { return []; }
    public function policies(string $organizationId): array { return []; }
};

$registry = new DomainModuleRegistry([$domain]);
$store = new class implements ConfigurationStoreInterface {
    /** @var list<array{organization_id: string, domain_name: string, actor_id: string}> */
    public array $calls = [];

    public function provision(
        string $organizationId,
        string $domainName,
        array $rules,
        array $policies,
        string $manifestHash,
        string $actorId,
    ): void {
        $this->calls[] = [
            'organization_id' => $organizationId,
            'domain_name' => $domainName,
            'actor_id' => $actorId,
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
        ),
    ),
]);

$configuration = new ConfigurationProvisioner(
    $registry,
    new ConfigurationValidator($registry),
    $store,
);
$provisioner = new ModuleTenantProvisioner($catalog, $migrations, $configuration);

$blocked = false;
try {
    $provisioner->provision('org-a', 'sales', '42');
} catch (RuntimeException $error) {
    $blocked = str_contains($error->getMessage(), 'schema is not ready')
        && str_contains($error->getMessage(), '20260910_000030_sales_v071_configuration_ownership');
}
if (!$blocked) throw new RuntimeException('Tenant provisioning did not reject an unapplied deployment migration.');
if ($store->calls !== []) throw new RuntimeException('Configuration was provisioned before schema readiness passed.');

$migrations->rows = [[
    'migration' => '20260910_000030_sales_v071_configuration_ownership',
    'applied_at' => '2026-09-11 00:00:00',
]];
$result = $provisioner->provision('org-a', 'sales', '42');

if (($result['modules'] ?? []) !== ['sales']) throw new RuntimeException('Provisioning result does not report the module.');
if (($result['domains'] ?? 0) !== 1) throw new RuntimeException('Runtime domain defaults were not provisioned.');
if (count($store->calls) !== 1) throw new RuntimeException('Configuration store was not called exactly once.');
if (($store->calls[0]['organization_id'] ?? null) !== 'org-a' || ($store->calls[0]['domain_name'] ?? null) !== 'sales' || ($store->calls[0]['actor_id'] ?? null) !== '42') {
    throw new RuntimeException('Tenant provisioning lost organization or actor ownership.');
}

echo "Module tenant provisioning invariants passed.\n";
