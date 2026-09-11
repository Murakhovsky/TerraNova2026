<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\KernelVersion;

if (!str_starts_with(KernelVersion::VERSION, '0.8.') || version_compare(KernelVersion::VERSION, '0.8.9', '<')) {
    throw new RuntimeException('COS Kernel module version readiness requires Kernel 0.8.9+ on the 0.8.x line.');
}

$resolver = (string) file_get_contents($root . '/app/Kernel/Module/ActiveModuleResolver.php');
foreach (['isCurrent(', 'installed_schema_version', 'version_current', 'schema_version_current', "'current'", '!$this->isCurrent'] as $needle) {
    if (!str_contains($resolver, $needle)) {
        throw new RuntimeException('ActiveModuleResolver is missing version readiness behavior: ' . $needle);
    }
}

$lifecycle = (string) file_get_contents($root . '/app/Kernel/Module/ModuleLifecycleManager.php');
foreach (['requires upgrade before enable', 'dependency %s requires upgrade', 'installedVersion !== $manifest->version', 'schemaVersion !== $manifest->schemaVersion'] as $needle) {
    if (!str_contains($lifecycle, $needle)) {
        throw new RuntimeException('ModuleLifecycleManager is missing stale-installation protection: ' . $needle);
    }
}

$control = (string) file_get_contents($root . '/app/Kernel/Module/ModuleControlService.php');
$enableStart = strpos($control, 'public function enable(');
$disableStart = strpos($control, 'public function disable(', $enableStart === false ? 0 : $enableStart);
if ($enableStart === false || $disableStart === false || $disableStart <= $enableStart) {
    throw new RuntimeException('Cannot isolate module enable control path.');
}
$enablePath = substr($control, $enableStart, $disableStart - $enableStart);
if (!str_contains($enablePath, 'isInstalled($organizationId, $moduleId)')) {
    throw new RuntimeException('Module control enable path no longer preserves explicit lifecycle semantics.');
}
foreach (['->upgrade(', "'upgrade'"] as $forbidden) {
    if (str_contains($enablePath, $forbidden)) {
        throw new RuntimeException('Module enable must not silently upgrade a stale installation.');
    }
}

$context = (string) file_get_contents($root . '/app/Kernel/Module/EffectiveModuleContext.php');
if (!str_contains($context, '$this->modules->describe($organizationId)')) {
    throw new RuntimeException('Effective module context no longer projects readiness-aware resolver state.');
}

echo "COS Kernel V0.8.9 module version readiness architecture passed.\n";
