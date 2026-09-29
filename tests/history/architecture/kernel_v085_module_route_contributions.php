<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Interfaces\Web\Routing\ModuleRouteContributorInterface;
use Interfaces\Web\Routing\PropertyModuleRouteContributor;
use Kernel\Module\KernelVersion;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;

if (version_compare(KernelVersion::VERSION, '0.8.5', '<')) {
    throw new RuntimeException('COS Kernel module route contributions require Kernel 0.8.5+.');
}

$catalog = new ModuleCatalog((new ModuleDiscovery($root . '/app/Domains'))->discover());
$property = $catalog->definition('property');
if ($property->contributions->apiRouteContributorServices !== ['propertyRouteContributor']) {
    throw new RuntimeException('Property must explicitly declare its Web route contribution service.');
}

$sales = $catalog->definition('sales');
if ($sales->contributions->apiRouteContributorServices !== []) {
    throw new RuntimeException('Sales SSR is Symfony-owned and must not declare a Phalcon route contribution.');
}

if (!is_subclass_of(PropertyModuleRouteContributor::class, ModuleRouteContributorInterface::class)) {
    throw new RuntimeException('Property route contributor must implement the Web module route contract.');
}

$webModuleSource = (string) file_get_contents($root . '/app/Interfaces/Web/Module.php');
if (!str_contains($webModuleSource, "getShared('cosModuleApiRouteContributors')")
    || !str_contains($webModuleSource, 'ModuleRouteContributorInterface')
) {
    throw new RuntimeException('Web shell must consume generic module route contributions.');
}

$webServicesSource = (string) file_get_contents($root . '/app/Bootstrap/WebApplicationServices.php');
foreach (["setShared('propertyRouteContributor'", 'new PropertyModuleRouteContributor()'] as $needle) {
    if (!str_contains($webServicesSource, $needle)) {
        throw new RuntimeException('Property route contributor is not registered in Web composition: ' . $needle);
    }
}
if (str_contains($webServicesSource, "setShared('salesRouteContributor'")) {
    throw new RuntimeException('Retired Sales route contributor wiring was restored.');
}

$contributorSource = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/PropertyModuleRouteContributor.php');
if (!str_contains($contributorSource, 'PublicPropertyRoutes::register')) {
    throw new RuntimeException('Property module route contributor lost its public route family.');
}
foreach (['ModuleRouteAccessGuard', 'beforeMatch', 'allows(', 'spl_object_id'] as $forbidden) {
    if (str_contains($contributorSource, $forbidden)) {
        throw new RuntimeException('Property route contributor must not own generic activation behavior: ' . $forbidden);
    }
}

$routeSource = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/PublicPropertyRoutes.php');
foreach (['/property/catalog', '/property/map', '/property/show/{slug:[a-z0-9-]+}'] as $expected) {
    if (!str_contains($routeSource, $expected)) {
        throw new RuntimeException('Property route family lost route: ' . $expected);
    }
}

echo "COS Kernel V0.8.5 module route contribution architecture passed.\n";
