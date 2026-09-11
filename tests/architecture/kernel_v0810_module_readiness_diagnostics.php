<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\KernelVersion;

if (!str_starts_with(KernelVersion::VERSION, '0.8.') || version_compare(KernelVersion::VERSION, '0.8.10', '<')) {
    throw new RuntimeException('COS Kernel module readiness diagnostics require Kernel 0.8.10+ on the 0.8.x line.');
}

$diagnosticPath = $root . '/app/Kernel/Module/ModuleReadinessDiagnostic.php';
if (!is_file($diagnosticPath)) throw new RuntimeException('ModuleReadinessDiagnostic is missing.');
$diagnostic = (string) file_get_contents($diagnosticPath);
foreach (['MigrationRunnerInterface', 'migrations->status()', 'UPGRADE_REQUIRED', 'SCHEMA_NOT_READY', 'SCHEMA_STATUS_UNAVAILABLE', 'DEPENDENCY_NOT_READY', 'DISABLED', 'READY'] as $needle) {
    if (!str_contains($diagnostic, $needle)) throw new RuntimeException('Module readiness diagnostic is missing: ' . $needle);
}
if (str_contains($diagnostic, 'getMessage()')) {
    throw new RuntimeException('Module readiness diagnostics must not expose raw migration/database errors.');
}

$services = (string) file_get_contents($root . '/app/Bootstrap/ModuleServices.php');
foreach (["setShared('cosModuleReadinessDiagnostic'", "getShared('cosMigrationRunner')", "getShared('cosActiveModuleResolver')"] as $needle) {
    if (!str_contains($services, $needle)) throw new RuntimeException('Module readiness composition is missing: ' . $needle);
}

$routes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/PlatformRoutes.php');
if (!str_contains($routes, "addGet('/api/platform/modules/readiness'")) {
    throw new RuntimeException('Module readiness endpoint is not registered as GET.');
}

$controller = (string) file_get_contents($root . '/app/Interfaces/Api/Controller/PlatformModuleController.php');
$readinessStart = strpos($controller, 'public function readinessAction()');
$installStart = strpos($controller, 'public function installAction(', $readinessStart === false ? 0 : $readinessStart);
if ($readinessStart === false || $installStart === false) throw new RuntimeException('Readiness controller action is missing.');
$readiness = substr($controller, $readinessStart, $installStart - $readinessStart);
foreach (['isAdmin($user)', "getShared('cosModuleReadinessDiagnostic')", 'diagnose($this->organization()->id())'] as $needle) {
    if (!str_contains($readiness, $needle)) throw new RuntimeException('Admin readiness action is missing: ' . $needle);
}

$indexStart = strpos($controller, 'public function indexAction()');
if ($indexStart === false || $readinessStart <= $indexStart) throw new RuntimeException('Cannot isolate normal module index action.');
$index = substr($controller, $indexStart, $readinessStart - $indexStart);
if (str_contains($index, 'cosModuleReadinessDiagnostic') || str_contains($index, 'MigrationRunner')) {
    throw new RuntimeException('Hot module-context endpoint must not run readiness/migration diagnostics.');
}

echo "COS Kernel V0.8.10 module readiness diagnostics architecture passed.\n";
