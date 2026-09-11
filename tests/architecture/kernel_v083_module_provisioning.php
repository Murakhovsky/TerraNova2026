<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\KernelVersion;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;
use Kernel\Module\ModuleTenantProvisioner;

if (!str_starts_with(KernelVersion::VERSION, '0.8.')) {
    throw new RuntimeException('COS Kernel V0.8 provisioning contract must remain on the 0.8.x compatibility line.');
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
