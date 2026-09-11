<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\KernelVersion;

if (version_compare(KernelVersion::VERSION, '0.8.8', '<')) {
    throw new RuntimeException('COS Kernel effective module context requires Kernel 0.8.8+.');
}

$contextPath = $root . '/app/Kernel/Module/EffectiveModuleContext.php';
if (!is_file($contextPath)) {
    throw new RuntimeException('EffectiveModuleContext is missing.');
}
$context = (string) file_get_contents($contextPath);
foreach (['ActiveModuleResolver', 'ModuleCapabilityRegistry', 'active_module_ids', 'active_capabilities', 'capabilitiesFor'] as $needle) {
    if (!str_contains($context, $needle)) {
        throw new RuntimeException('EffectiveModuleContext is missing canonical state: ' . $needle);
    }
}

$services = (string) file_get_contents($root . '/app/Bootstrap/ModuleServices.php');
foreach (["setShared('cosEffectiveModuleContext'", "getShared('cosActiveModuleResolver')", "getShared('cosModuleCapabilityRegistry')"] as $needle) {
    if (!str_contains($services, $needle)) {
        throw new RuntimeException('Module composition is missing effective context wiring: ' . $needle);
    }
}

$controller = (string) file_get_contents($root . '/app/Interfaces/Api/Controller/PlatformModuleController.php');
if (!str_contains($controller, "getShared('cosEffectiveModuleContext')") || !str_contains($controller, 'EffectiveModuleContext')) {
    throw new RuntimeException('Platform module API does not consume the canonical effective module context.');
}
foreach (['ModuleCapabilityRegistry', 'array_map('] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('Platform module controller still assembles module state itself: ' . $forbidden);
    }
}

$routes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/PlatformRoutes.php');
if (!str_contains($routes, '/api/platform/modules')) {
    throw new RuntimeException('Effective module context is not exposed through the platform module endpoint.');
}

echo "COS Kernel V0.8.8 effective module context architecture passed.\n";
