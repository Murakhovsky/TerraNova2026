<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\KernelVersion;

if (!str_starts_with(KernelVersion::VERSION, '0.8.') || version_compare(KernelVersion::VERSION, '0.8.7', '<')) {
    throw new RuntimeException('COS Kernel generic module route isolation requires Kernel 0.8.7+ on the 0.8.x line.');
}

$registrar = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/ModuleRouteRegistrar.php');
foreach (['ModuleRouteAccessGuard', 'ModuleRouteContributorInterface', 'spl_object_id', 'beforeMatch', 'allows($moduleId)'] as $needle) {
    if (!str_contains($registrar, $needle)) {
        throw new RuntimeException('Generic module route registrar is missing isolation behavior: ' . $needle);
    }
}

$sales = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/SalesModuleRouteContributor.php');
foreach (['SalesRoutes::register', 'SalesTeamRoutes::register', 'SalesIntegrationRoutes::register', 'SalesAdministrationRoutes::register'] as $needle) {
    if (!str_contains($sales, $needle)) {
        throw new RuntimeException('Sales route contributor lost route family: ' . $needle);
    }
}
foreach (['ModuleRouteAccessGuard', 'beforeMatch', 'allows(', 'spl_object_id'] as $forbidden) {
    if (str_contains($sales, $forbidden)) {
        throw new RuntimeException('Sales route contributor still owns generic activation behavior: ' . $forbidden);
    }
}

$webModule = (string) file_get_contents($root . '/app/Interfaces/Web/Module.php');
foreach (["getShared('moduleRouteRegistrar')", 'ModuleRouteRegistrar', '$routeRegistrar->register($moduleId, $service, $router)', "getShared('cosModuleApiRouteContributors')"] as $needle) {
    if (!str_contains($webModule, $needle)) {
        throw new RuntimeException('Web shell is missing generic route isolation wiring: ' . $needle);
    }
}

$services = (string) file_get_contents($root . '/app/Bootstrap/WebApplicationServices.php');
foreach (["setShared('moduleRouteRegistrar'", 'new ModuleRouteRegistrar(', "setShared('salesRouteContributor'", 'new SalesModuleRouteContributor()'] as $needle) {
    if (!str_contains($services, $needle)) {
        throw new RuntimeException('Web composition is missing generic route registrar wiring: ' . $needle);
    }
}

$manifest = (string) file_get_contents($root . '/app/Domains/Sales/module.php');
if (!str_contains($manifest, "'salesRouteContributor'")) {
    throw new RuntimeException('Sales manifest no longer declares its route contributor.');
}

$moduleServices = (string) file_get_contents($root . '/app/Bootstrap/ModuleServices.php');
foreach (['\'module_id\' => $definition->manifest->id', '\'service\' => $this->getShared($serviceId)'] as $needle) {
    if (!str_contains($moduleServices, $needle)) {
        throw new RuntimeException('Module route contributions lost module ownership metadata: ' . $needle);
    }
}

echo "COS Kernel V0.8.7 generic module route isolation architecture passed.\n";
