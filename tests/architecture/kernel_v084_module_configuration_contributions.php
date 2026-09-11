<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Sales\Bootstrap\SalesModuleConfigurationProvisioner;
use Kernel\Module\Contract\ModuleConfigurationProvisionerInterface;
use Kernel\Module\KernelVersion;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;

if (KernelVersion::VERSION !== '0.8.4') {
    throw new RuntimeException('COS Kernel V0.8.4 contribution contract version mismatch.');
}

$catalog = new ModuleCatalog((new ModuleDiscovery($root . '/app/Domains'))->discover());
$sales = $catalog->definition('sales');
if ($sales->contributions->configurationProvisionerServices !== ['salesModuleConfigurationProvisioner']) {
    throw new RuntimeException('Sales must explicitly declare its tenant configuration provisioner.');
}

if (!is_subclass_of(SalesModuleConfigurationProvisioner::class, ModuleConfigurationProvisionerInterface::class)) {
    throw new RuntimeException('Sales configuration provisioner must implement the Kernel module contract.');
}

$tenantProvisionerSource = (string) file_get_contents($root . '/app/Kernel/Module/ModuleTenantProvisioner.php');
foreach (['runtimeModuleService', 'Configuration\\Service\\ConfigurationProvisioner'] as $forbidden) {
    if (str_contains($tenantProvisionerSource, $forbidden)) {
        throw new RuntimeException('ModuleTenantProvisioner must not infer configuration ownership from runtime composition: ' . $forbidden);
    }
}
if (!str_contains($tenantProvisionerSource, 'ModuleConfigurationProvisionerInterface')) {
    throw new RuntimeException('ModuleTenantProvisioner must depend on the explicit module configuration contract.');
}

$moduleServicesSource = (string) file_get_contents($root . '/app/Bootstrap/ModuleServices.php');
if (!str_contains($moduleServicesSource, 'instanceof ModuleConfigurationProvisionerInterface')) {
    throw new RuntimeException('Composition root must validate configuration provisioner contributions.');
}

$kernelServicesSource = (string) file_get_contents($root . '/app/Bootstrap/KernelServices.php');
if (!str_contains($kernelServicesSource, "getShared('cosModuleConfigurationProvisioners')")) {
    throw new RuntimeException('Kernel composition must feed manifest-owned configuration provisioners into tenant provisioning.');
}

echo "COS Kernel V0.8.4 module configuration contribution architecture passed.\n";
