<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\KernelVersion;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;
use Kernel\Module\ModuleTenantProvisioner;

if (version_compare(KernelVersion::VERSION, '0.8.3', '<')) {
    throw new RuntimeException('COS Kernel module provisioning contract requires Kernel 0.8.3+.');
}

$catalog = new ModuleCatalog((new ModuleDiscovery($root . '/app/Domains'))->discover());
if (!$catalog->has('sales') || !$catalog->has('property')) {
    throw new RuntimeException('Expected Sales and Property manifests were not discovered.');
}

$sales = $catalog->definition('sales');
if ($sales->contributions->migrationFiles === []) {
    throw new RuntimeException('Sales must declare deployment migration ownership for provisioning preflight.');
}

if (!class_exists(ModuleTenantProvisioner::class)) {
    throw new RuntimeException('Module tenant provisioner is missing.');
}

echo "COS Kernel V0.8.3 module provisioning architecture passed.\n";
