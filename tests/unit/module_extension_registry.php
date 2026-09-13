<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleExtensionPoint;
use Kernel\Module\ModuleExtensionRegistry;
use Kernel\Module\ModuleManifest;

$catalog = new ModuleCatalog([
    new ModuleDefinition(
        new ModuleManifest('sales', 'Sales', '1.0.0'),
        new ModuleContributions(
            apiRouteContributorServices: ['salesRoutes'],
            configurationProvisionerServices: ['salesConfiguration'],
            extensionServices: [
                ModuleExtensionPoint::WEB_NAVIGATION => ['salesNavigation'],
                'web.dashboard' => ['salesDashboard'],
            ],
        ),
    ),
    new ModuleDefinition(
        new ModuleManifest('property', 'Property', '1.0.0'),
        new ModuleContributions(
            extensionServices: [ModuleExtensionPoint::WEB_NAVIGATION => ['propertyNavigation']],
        ),
    ),
]);

$registry = new ModuleExtensionRegistry($catalog);

$routes = $registry->for(ModuleExtensionPoint::API_ROUTES);
if (count($routes) !== 1 || $routes[0]->moduleId !== 'sales' || $routes[0]->serviceId !== 'salesRoutes') {
    throw new RuntimeException('API route extension ownership is incorrect.');
}

$config = $registry->for(ModuleExtensionPoint::TENANT_CONFIGURATION);
if (count($config) !== 1 || $config[0]->serviceId !== 'salesConfiguration') {
    throw new RuntimeException('Tenant configuration extension was not normalized.');
}

$navigation = $registry->for(ModuleExtensionPoint::WEB_NAVIGATION);
if (count($navigation) !== 2
    || $navigation[0]->moduleId !== 'sales'
    || $navigation[1]->moduleId !== 'property'
) {
    throw new RuntimeException('Generic Web navigation extensions are incorrect.');
}

if ($registry->extensionPoints() !== ['api.routes', 'tenant.configuration', 'web.dashboard', 'web.navigation']) {
    throw new RuntimeException('Extension point discovery is not deterministic.');
}

$parsed = ModuleContributions::fromArray([
    'extension_services' => [
        ModuleExtensionPoint::WEB_NAVIGATION => ['navigationService'],
    ],
]);
if (($parsed->extensionServices[ModuleExtensionPoint::WEB_NAVIGATION] ?? null) !== ['navigationService']) {
    throw new RuntimeException('Manifest extension services were not parsed.');
}
if (!in_array('navigationService', $parsed->allServiceIds(), true)) {
    throw new RuntimeException('Extension service ids are missing from allServiceIds().');
}

$normalized = (new ModuleContributions(
    apiRouteContributorServices: ['legacyRoutes'],
    configurationProvisionerServices: ['legacyConfiguration'],
    extensionServices: [
        ModuleExtensionPoint::API_ROUTES => ['genericRoutes'],
        ModuleExtensionPoint::EVENT_CONSUMERS => ['salesConsumer'],
    ],
))->normalizedExtensionServices();
if (($normalized[ModuleExtensionPoint::API_ROUTES] ?? null) !== ['legacyRoutes', 'genericRoutes']) {
    throw new RuntimeException('Legacy and generic API route contributions were not normalized in declaration order.');
}
if (($normalized[ModuleExtensionPoint::TENANT_CONFIGURATION] ?? null) !== ['legacyConfiguration']) {
    throw new RuntimeException('Legacy tenant configuration contribution was not normalized.');
}
if (($normalized[ModuleExtensionPoint::EVENT_CONSUMERS] ?? null) !== ['salesConsumer']) {
    throw new RuntimeException('Generic event consumer contribution was lost during normalization.');
}

try {
    new ModuleExtensionRegistry(new ModuleCatalog([
        new ModuleDefinition(
            new ModuleManifest('duplicate', 'Duplicate', '1.0.0'),
            new ModuleContributions(
                apiRouteContributorServices: ['sameRoutes'],
                extensionServices: [ModuleExtensionPoint::API_ROUTES => ['sameRoutes']],
            ),
        ),
    ]));
    throw new RuntimeException('Expected duplicate extension contribution rejection.');
} catch (InvalidArgumentException $error) {
    if (!str_contains($error->getMessage(), 'Duplicate extension contribution')) {
        throw $error;
    }
}

$registrySource = (string) file_get_contents($root . '/app/Kernel/Module/ModuleExtensionRegistry.php');
foreach (['apiRouteContributorServices', 'configurationProvisionerServices', 'extensionServices'] as $legacyShape) {
    if (str_contains($registrySource, '->' . $legacyShape)) {
        throw new RuntimeException('ModuleExtensionRegistry still depends directly on manifest shape: ' . $legacyShape);
    }
}

if (ModuleExtensionRegistry::API_ROUTES !== ModuleExtensionPoint::API_ROUTES
    || ModuleExtensionRegistry::TENANT_CONFIGURATION !== ModuleExtensionPoint::TENANT_CONFIGURATION
    || ModuleExtensionRegistry::EVENT_CONSUMERS !== ModuleExtensionPoint::EVENT_CONSUMERS
) {
    throw new RuntimeException('Backward-compatible ModuleExtensionRegistry aliases drifted from canonical extension point names.');
}

echo "Module extension registry invariants passed.\n";
