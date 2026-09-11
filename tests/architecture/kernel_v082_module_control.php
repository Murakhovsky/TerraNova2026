<?php
declare(strict_types=1);

use Kernel\Module\KernelVersion;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

if (KernelVersion::VERSION !== '0.8.2') {
    throw new RuntimeException('Kernel runtime version must match COS Kernel V0.8.2.');
}

$control = (string) file_get_contents($root . '/app/Kernel/Module/ModuleControlService.php');
foreach (['AuditEntry', 'EventBus', 'TransactionManagerInterface', 'platform.module.lifecycle_changed', "'MODULE_LIFECYCLE'"] as $boundary) {
    if (!str_contains($control, $boundary)) throw new RuntimeException('Module control boundary is missing: ' . $boundary);
}

$routes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/PlatformRoutes.php');
foreach (['/api/platform/modules', '/install', '/upgrade', '/enable', '/disable', '/uninstall'] as $route) {
    if (!str_contains($routes, $route)) throw new RuntimeException('Platform module route is missing: ' . $route);
}

$webModule = (string) file_get_contents($root . '/app/Interfaces/Web/Module.php');
if (!str_contains($webModule, 'PlatformRoutes::register($router)')) throw new RuntimeException('Platform routes are not wired into the Web module.');

$controller = (string) file_get_contents($root . '/app/Interfaces/Api/Controller/PlatformModuleController.php');
foreach (['isAdmin($user)', 'validMutation()', 'cosModuleControlService', 'KernelVersion::VERSION', 'cosModuleCapabilityRegistry'] as $boundary) {
    if (!str_contains($controller, $boundary)) throw new RuntimeException('Platform module controller boundary is missing: ' . $boundary);
}

$bootstrap = (string) file_get_contents($root . '/app/Bootstrap/KernelServices.php');
if (!str_contains($bootstrap, "'cosModuleControlService'")) throw new RuntimeException('Module control service is not registered in composition root.');

echo "COS Kernel V0.8.2 module control plane contract passed.\n";
