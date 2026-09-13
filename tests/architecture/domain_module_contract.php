<?php
declare(strict_types=1);

use Domains\Sales\Bootstrap\SalesDomainModule;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\AgentProvidingModuleInterface;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Module\Contract\PolicyProvidingModuleInterface;
use Kernel\Module\Contract\RuleProvidingModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\KernelVersion;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleDiscovery;
use Kernel\Module\ModuleManifest;
use Kernel\Module\VersionConstraint;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$definitions = (new ModuleDiscovery($root . '/app/Domains'))->discover();
if ($definitions === []) {
    throw new RuntimeException('Domain module discovery returned no modules.');
}

$catalog = new ModuleCatalog($definitions);
$catalog->assertCompatibility(KernelVersion::VERSION);

$runtimeBoundaryMethods = array_map(
    static fn (ReflectionMethod $method): string => $method->getName(),
    (new ReflectionClass(DomainModuleInterface::class))->getMethods(),
);
sort($runtimeBoundaryMethods);
if ($runtimeBoundaryMethods !== ['name']) {
    throw new RuntimeException('Base DomainModuleInterface must expose identity only; optional runtime behavior belongs to capability contracts.');
}

$minimalRuntimeModule = new class implements DomainModuleInterface {
    public function name(): string { return 'minimal'; }
};
$minimalRegistry = new DomainModuleRegistry([$minimalRuntimeModule]);
if (count($minimalRegistry->modules()) !== 1 || $minimalRegistry->actionHandlers() !== []) {
    throw new RuntimeException('A minimal runtime domain module cannot be registered without optional capabilities.');
}
if ($minimalRegistry->ownerOfEvent('minimal.event') !== null || $minimalRegistry->ownerOfAction('minimal.action') !== null) {
    throw new RuntimeException('A minimal runtime module unexpectedly claimed optional event/action ownership.');
}

$salesRuntime = new ReflectionClass(SalesDomainModule::class);
foreach ([
    EventOwningModuleInterface::class,
    ActionOwningModuleInterface::class,
    AgentProvidingModuleInterface::class,
    RuleProvidingModuleInterface::class,
    PolicyProvidingModuleInterface::class,
] as $capabilityContract) {
    if (!$salesRuntime->implementsInterface($capabilityContract)) {
        throw new RuntimeException('Sales runtime module is missing capability contract: ' . $capabilityContract);
    }
}

$moduleIds = [];
$capabilities = [];
foreach ($definitions as $definition) {
    if (!$definition instanceof ModuleDefinition) {
        throw new RuntimeException('Module discovery returned an invalid definition.');
    }

    $manifest = $definition->manifest;
    $moduleIds[] = $manifest->id;

    if ($definition->sourcePath === null || !is_file($definition->sourcePath)) {
        throw new RuntimeException(sprintf('Module %s has no source module.php.', $manifest->id));
    }

    VersionConstraint::assertVersion($manifest->version);
    VersionConstraint::assertVersion($manifest->schemaVersion);
    VersionConstraint::assertConstraint($manifest->kernelConstraint);

    foreach ($definition->contributions->migrationFiles as $migrationFile) {
        $path = $root . '/' . $migrationFile;
        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'Module %s declares missing migration %s.',
                $manifest->id,
                $migrationFile,
            ));
        }
    }

    foreach ($definition->contributions->capabilities as $capability) {
        if (isset($capabilities[$capability])) {
            throw new RuntimeException(sprintf(
                'Capability %s is owned by both %s and %s.',
                $capability,
                $capabilities[$capability],
                $manifest->id,
            ));
        }
        $capabilities[$capability] = $manifest->id;
    }
}

if (count($moduleIds) !== count(array_unique($moduleIds))) {
    throw new RuntimeException('Discovered module ids are not unique.');
}

// Exercise compatibility and dependency rules independently of application manifests.
if (!VersionConstraint::matches('0.7.1', '^0.7.1') || VersionConstraint::matches('0.8.0', '^0.7.1')) {
    throw new RuntimeException('Caret version constraints are not enforced correctly.');
}
if (!VersionConstraint::matches('1.4.2', '>=1.4.0 <2.0.0')) {
    throw new RuntimeException('Range version constraints are not enforced correctly.');
}

$dependency = new ModuleManifest('dependency', 'Dependency', '1.4.2');
new ModuleCatalog([
    $dependency,
    new ModuleManifest(
        'consumer',
        'Consumer',
        '1.0.0',
        dependencies: ['dependency'],
        dependencyConstraints: ['dependency' => '^1.4.0'],
    ),
]);

$bootstrap = (string) file_get_contents($root . '/app/Bootstrap/ModuleServices.php');
foreach ([
    '/Domains/Sales/module.php',
    '/Domains/Diagnostic/module.php',
    '/Domains/Property/module.php',
    "'salesDomainModule'",
    "'salesCrmInboxJobHandler'",
] as $manualCoupling) {
    if (str_contains($bootstrap, $manualCoupling)) {
        throw new RuntimeException('Central module bootstrap still contains domain-specific coupling: ' . $manualCoupling);
    }
}

foreach ([
    'ModuleDiscovery',
    'cosModuleLifecycleManager',
    'cosModuleCapabilityRegistry',
    'cosModuleApiRouteContributors',
    'cosModuleConfigurationProvisioners',
] as $requiredKernelBoundary) {
    if (!str_contains($bootstrap, $requiredKernelBoundary)) {
        throw new RuntimeException('Module platform boundary is missing: ' . $requiredKernelBoundary);
    }
}

$domainBoundary = (string) file_get_contents($root . '/tests/architecture/domain_boundaries.php');
if (str_contains($domainBoundary, '$expected = [')) {
    throw new RuntimeException('Domain boundary test still uses a fixed domain allowlist.');
}

echo sprintf(
    "Domain module contract passed: %d modules, %d capabilities, Kernel %s.\n",
    count($definitions),
    count($capabilities),
    KernelVersion::VERSION,
);
