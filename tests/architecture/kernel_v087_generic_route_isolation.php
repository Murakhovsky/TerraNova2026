<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\KernelVersion;

if (version_compare(KernelVersion::VERSION, '0.8.7', '<')) {
    throw new RuntimeException('COS Kernel generic module route isolation requires Kernel 0.8.7+.');
}

$registrar = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/ModuleRouteRegistrar.php');
foreach (['ModuleRouteAccessGuard', 'ModuleRouteContributorInterface', 'spl_object_id', 'beforeMatch', 'allows($moduleId)'] as $needle) {
    if (!str_contains($registrar, $needle)) {
        throw new RuntimeException('Generic module route registrar is missing isolation behavior: ' . $needle);
    }
}

$property = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/PropertyModuleRouteContributor.php');
if (!str_contains($property, 'PublicPropertyRoutes::register')) {
    throw new RuntimeException('Property route contributor lost route family.');
}
foreach (['ModuleRouteAccessGuard', 'beforeMatch', 'allows(', 'spl_object_id'] as $forbidden) {
    if (str_contains($property, $forbidden)) {
        throw new RuntimeException('Property route contributor still owns generic activation behavior: ' . $forbidden);
    }
}

$webModule = (string) file_get_contents($root . '/app/Interfaces/Web/Module.php');
foreach (["getShared('moduleRouteRegistrar')", 'ModuleRouteRegistrar', '$routeRegistrar->register($moduleId, $service, $router)', "getShared('cosModuleApiRouteContributors')"] as $needle) {
    if (!str_contains($webModule, $needle)) {
        throw new RuntimeException('Web shell is missing generic route isolation wiring: ' . $needle);
    }
}

$services = (string) file_get_contents($root . '/app/Bootstrap/WebApplicationServices.php');
foreach (["setShared('moduleRouteRegistrar'", 'new ModuleRouteRegistrar(', "setShared('propertyRouteContributor'", 'new PropertyModuleRouteContributor()'] as $needle) {
    if (!str_contains($services, $needle)) {
        throw new RuntimeException('Web composition is missing generic route registrar wiring: ' . $needle);
    }
}
if (str_contains($services, "setShared('salesRouteContributor'")) {
    throw new RuntimeException('Sales must not return to the legacy module route runtime.');
}

$propertyManifest = (string) file_get_contents($root . '/app/Domains/Property/module.php');
if (!str_contains($propertyManifest, "'propertyRouteContributor'")) {
    throw new RuntimeException('Property manifest no longer declares its route contributor.');
}
$salesManifest = (string) file_get_contents($root . '/app/Domains/Sales/module.php');
if (str_contains($salesManifest, "'salesRouteContributor'")) {
    throw new RuntimeException('Sales manifest restored its retired Phalcon route contributor.');
}

$moduleServices = (string) file_get_contents($root . '/app/Bootstrap/ModuleServices.php');
foreach (["'module_id' => \$extension->moduleId", "'service' => \$this->getShared(\$extension->serviceId)"] as $needle) {
    if (!str_contains($moduleServices, $needle)) {
        throw new RuntimeException('Module route extensions lost module ownership metadata: ' . $needle);
    }
}

echo "COS Kernel V0.8.7 generic module route isolation architecture passed.\n";
