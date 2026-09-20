<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Property\Bootstrap\PropertyDomainModule;
use Domains\Property\Bootstrap\PropertyModuleConfigurationProvisioner;
use Kernel\Module\Contract\ModuleConfigurationProvisionerInterface;
use Kernel\Module\Contract\RuleProvidingModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;

$catalog = new ModuleCatalog((new ModuleDiscovery($root . '/app/Domains'))->discover());
$property = $catalog->definition('property');
$contributions = $property->contributions;

if (version_compare($property->manifest->version, '0.2.1', '<')) {
    throw new RuntimeException('Property runtime manifest must stay at V0.2.1+.');
}
if ($contributions->runtimeModuleService !== 'propertyDomainModule') {
    throw new RuntimeException('Property must expose its runtime module contribution.');
}
if ($contributions->apiRouteContributorServices !== []) {
    throw new RuntimeException('Property routes are Symfony-owned and must not restore module route contributors.');
}
if ($contributions->configurationProvisionerServices !== ['propertyModuleConfigurationProvisioner']) {
    throw new RuntimeException('Property must own tenant configuration provisioning.');
}

foreach (['property.registry','property.read','property.write','property.intake','property.media','property.catalog'] as $capability) {
    if (!in_array($capability, $contributions->capabilities, true)) {
        throw new RuntimeException('Property lost required runtime capability: ' . $capability);
    }
}

if (!is_subclass_of(PropertyDomainModule::class, DomainModuleInterface::class)) {
    throw new RuntimeException('Property runtime module must implement DomainModuleInterface.');
}
if (!is_subclass_of(PropertyDomainModule::class, RuleProvidingModuleInterface::class)) {
    throw new RuntimeException('Property runtime must expose its rule capability.');
}
if ((new PropertyDomainModule())->name() !== 'property') {
    throw new RuntimeException('Property runtime module reports an invalid module id.');
}
if (!is_subclass_of(PropertyModuleConfigurationProvisioner::class, ModuleConfigurationProvisionerInterface::class)) {
    throw new RuntimeException('Property configuration provisioner must implement the Kernel contract.');
}

$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
foreach ([
    'Domains\\Property\\Bootstrap\\PropertyDomainModule:',
    'Domains\\Property\\Bootstrap\\PropertyModuleConfigurationProvisioner:',
    'Domains\\Property\\Infrastructure\\Persistence\\MySql\\MysqlPropertyCanonicalRuntimeRepository:',
] as $service) {
    if (!str_contains($services, $service)) {
        throw new RuntimeException('Property Symfony composition missing: ' . $service);
    }
}

foreach ([
    'app/Interfaces/Web/Routing/PropertyModuleRouteContributor.php',
    'app/Interfaces/Web/Routing/PropertyRuntimeRoutes.php',
    'app/Interfaces/Api/Controller/PropertyRuntimeController.php',
    'app/Interfaces/Api/Controller/PropertyCanonicalController.php',
    'app/Bootstrap/PropertyServices.php',
] as $retired) {
    if (is_file($root . '/' . $retired)) {
        throw new RuntimeException('Retired Property transport/composition artifact was restored: ' . $retired);
    }
}

$symfonyRoutes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['/api/v1/properties', '/api/v1/property-inventory/{id}/status', '/api/v1/property-inventory/{id}/reservations'] as $route) {
    if (!str_contains($symfonyRoutes, $route)) {
        throw new RuntimeException('Canonical Symfony Property route is missing: ' . $route);
    }
}

echo "Property V0.2.1 runtime architecture: OK\n";
