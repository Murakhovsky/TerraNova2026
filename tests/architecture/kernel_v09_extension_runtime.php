<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\KernelVersion;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;
use Kernel\Module\ModuleExtensionRegistry;

if (KernelVersion::VERSION !== '0.9.0') {
    throw new RuntimeException('Kernel machine version is not aligned with V0.9.0.');
}

$definitions = (new ModuleDiscovery($root . '/app/Domains'))->discover();
$registry = new ModuleExtensionRegistry(new ModuleCatalog($definitions));
$navigation = $registry->for('web.navigation');
$owners = [];
foreach ($navigation as $contribution) {
    $owners[$contribution->moduleId] = $contribution->serviceId;
}
foreach ([
    'sales' => 'salesNavigationContributor',
    'property' => 'propertyNavigationContributor',
    'diagnostic' => 'diagnosticNavigationContributor',
] as $moduleId => $serviceId) {
    if (($owners[$moduleId] ?? null) !== $serviceId) {
        throw new RuntimeException(sprintf('Module %s does not own its Web navigation extension.', $moduleId));
    }
}

$moduleBootstrap = (string) file_get_contents($root . '/app/Bootstrap/ModuleServices.php');
foreach (['cosModuleExtensionRegistry', 'ModuleExtensionRegistry::API_ROUTES', 'ModuleExtensionRegistry::TENANT_CONFIGURATION', "->for('web.navigation')"] as $needle) {
    if (!str_contains($moduleBootstrap, $needle)) {
        throw new RuntimeException('Extension runtime bootstrap is incomplete: ' . $needle);
    }
}

$webBootstrap = (string) file_get_contents($root . '/app/Bootstrap/WebApplicationServices.php');
if (str_contains($webBootstrap, "'webModuleNavigationContributors'")) {
    throw new RuntimeException('Web navigation still uses the old hardcoded contributor list.');
}
if (!str_contains($webBootstrap, "'cosModuleWebNavigationContributors'")) {
    throw new RuntimeException('Web navigation is not consuming module-owned extension contributions.');
}

$webModule = (string) file_get_contents($root . '/app/Interfaces/Web/Module.php');
if (!str_contains($webModule, "'cosModuleApiRouteContributors'")) {
    throw new RuntimeException('Web routes are not consuming module-owned route extensions.');
}

$guard = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/ModuleRouteAccessGuard.php');
if (!str_contains($guard, 'ActiveModuleResolver')) {
    throw new RuntimeException('Module route access is not tenant-aware.');
}

echo "Kernel V0.9 extension runtime architecture passed.\n";
