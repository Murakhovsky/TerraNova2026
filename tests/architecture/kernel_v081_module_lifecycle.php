<?php
declare(strict_types=1);

use Kernel\Module\KernelVersion;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;
use Kernel\Module\VersionConstraint;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

if (version_compare(KernelVersion::VERSION, '0.8.1', '<')) {
    throw new RuntimeException('Kernel runtime version is older than the V0.8.1 lifecycle contract.');
}

$definitions = (new ModuleDiscovery($root . '/app/Domains'))->discover();
$catalog = new ModuleCatalog($definitions);
$catalog->assertCompatibility(KernelVersion::VERSION);
foreach ($definitions as $definition) {
    if (!VersionConstraint::matches(KernelVersion::VERSION, $definition->manifest->kernelConstraint)) {
        throw new RuntimeException(sprintf('Module %s does not accept Kernel %s.', $definition->manifest->id, KernelVersion::VERSION));
    }
}

$stateContract = (string) file_get_contents($root . '/app/Kernel/Module/Contract/ModuleStateRepositoryInterface.php');
if (str_contains($stateContract, '$configuration')) {
    throw new RuntimeException('Module state contract still couples activation and configuration writes.');
}

$mysqlStateRepository = (string) file_get_contents($root . '/app/Infrastructure/Module/MysqlModuleStateRepository.php');
foreach (['configuration_json = VALUES(configuration_json)', '(organization_id, module_id, enabled, configuration_json)'] as $unsafeWrite) {
    if (str_contains($mysqlStateRepository, $unsafeWrite)) {
        throw new RuntimeException('Activation write can still overwrite module configuration: ' . $unsafeWrite);
    }
}

$lifecycle = (string) file_get_contents($root . '/app/Kernel/Module/ModuleLifecycleManager.php');
foreach (['assertNoEnabledDependents', 'enableRecursive', 'Cannot %s %s while dependent module %s is enabled.'] as $requiredInvariant) {
    if (!str_contains($lifecycle, $requiredInvariant)) {
        throw new RuntimeException('Module lifecycle invariant is missing: ' . $requiredInvariant);
    }
}

$resolver = (string) file_get_contents($root . '/app/Kernel/Module/ActiveModuleResolver.php');
foreach (['isConfiguredEnabled', 'isInstalled', "'configured_enabled'", "'active'"] as $stateDimension) {
    if (!str_contains($resolver, $stateDimension)) {
        throw new RuntimeException('Module state dimension is missing: ' . $stateDimension);
    }
}

echo sprintf("COS Kernel V0.8 lifecycle contract passed on Kernel %s.\n", KernelVersion::VERSION);
