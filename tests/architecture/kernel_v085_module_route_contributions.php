<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Interfaces\Web\Routing\ModuleRouteContributorInterface;
use Interfaces\Web\Routing\SalesModuleRouteContributor;
use Kernel\Module\KernelVersion;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;

if (version_compare(KernelVersion::VERSION, '0.8.5', '<')) {
    throw new RuntimeException('COS Kernel module route contributions require Kernel 0.8.5+.');
}

$catalog = new ModuleCatalog((new ModuleDiscovery($root . '/app/Domains'))->discover());
$sales = $catalog->definition('sales');
if ($sales->contributions->apiRouteContributorServices !== ['salesRouteContributor']) {
    throw new RuntimeException('Sales must explicitly declare its Web/API route contribution service.');
}

if (!is_subclass_of(SalesModuleRouteContributor::class, ModuleRouteContributorInterface::class)) {
    throw new RuntimeException('Sales route contributor must implement the Web module route contract.');
}

$webModuleSource = (string) file_get_contents($root . '/app/Interfaces/Web/Module.php');
foreach (['SalesRoutes::register', 'SalesTeamRoutes::register', 'SalesIntegrationRoutes::register', 'SalesAdministrationRoutes::register'] as $forbidden) {
    if (str_contains($webModuleSource, $forbidden)) {
        throw new RuntimeException('Web shell still hardcodes a Sales route family: ' . $forbidden);
    }
}
if (!str_contains($webModuleSource, "getShared('cosModuleApiRouteContributors')")
    || !str_contains($webModuleSource, 'ModuleRouteContributorInterface')
) {
    throw new RuntimeException('Web shell must consume generic module route contributions.');
}

$webServicesSource = (string) file_get_contents($root . '/app/Bootstrap/WebApplicationServices.php');
if (!str_contains($webServicesSource, "setShared('salesRouteContributor'")) {
    throw new RuntimeException('Sales route contributor is not registered in Web composition.');
}

$contributorSource = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/SalesModuleRouteContributor.php');
$routeFamilies = [
    'SalesRoutes::register',
    'SalesTeamRoutes::register',
    'SalesIntegrationRoutes::register',
    'SalesAdministrationRoutes::register',
];
foreach ($routeFamilies as $registration) {
    if (!str_contains($contributorSource, $registration)) {
        throw new RuntimeException('Sales module route contributor lost route family: ' . $registration);
    }
}

$routeSources = '';
foreach (['SalesRoutes.php', 'SalesTeamRoutes.php', 'SalesIntegrationRoutes.php', 'SalesAdministrationRoutes.php'] as $file) {
    $routeSources .= (string) file_get_contents($root . '/app/Interfaces/Web/Routing/' . $file);
}
foreach (['/sales', '/api/sales/search', '/sales/admin/teams', '/sales/admin/integrations', '/sales/admin/health'] as $expected) {
    if (!str_contains($routeSources, $expected)) {
        throw new RuntimeException('Sales route family lost route: ' . $expected);
    }
}

echo "COS Kernel V0.8.5 module route contribution architecture passed.\n";
