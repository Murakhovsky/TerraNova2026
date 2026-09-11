<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleExtensionRegistry;
use Kernel\Module\ModuleManifest;

$catalog = new ModuleCatalog([
    new ModuleDefinition(
        new ModuleManifest('sales', 'Sales', '1.0.0'),
        new ModuleContributions(
            apiRouteContributorServices: ['salesRoutes'],
            configurationProvisionerServices: ['salesConfiguration'],
            extensionServices: [
                'web.navigation' => ['salesNavigation'],
                'web.dashboard' => ['salesDashboard'],
            ],
        ),
    ),
    new ModuleDefinition(
        new ModuleManifest('property', 'Property', '1.0.0'),
        new ModuleContributions(
            extensionServices: ['web.navigation' => ['propertyNavigation']],
        ),
    ),
]);

$registry = new ModuleExtensionRegistry($catalog);

$routes = $registry->for(ModuleExtensionRegistry::API_ROUTES);
if (count($routes) !== 1 || $routes[0]->moduleId !== 'sales' || $routes[0]->serviceId !== 'salesRoutes') {
    throw new RuntimeException('API route extension ownership is incorrect.');
}

$config = $registry->for(ModuleExtensionRegistry::TENANT_CONFIGURATION);
if (count($config) !== 1 || $config[0]->serviceId !== 'salesConfiguration') {
    throw new RuntimeException('Tenant configuration extension was not normalized.');
}

$navigation = $registry->for('web.navigation');
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
        'web.navigation' => ['navigationService'],
    ],
]);
if (($parsed->extensionServices['web.navigation'] ?? null) !== ['navigationService']) {
    throw new RuntimeException('Manifest extension services were not parsed.');
}
if (!in_array('navigationService', $parsed->allServiceIds(), true)) {
    throw new RuntimeException('Extension service ids are missing from allServiceIds().');
}

echo "Module extension registry invariants passed.\n";
