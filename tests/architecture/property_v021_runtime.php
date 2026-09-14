<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Property\Bootstrap\PropertyDomainModule;
use Domains\Property\Bootstrap\PropertyModuleConfigurationProvisioner;
use Interfaces\Web\Routing\ModuleRouteContributorInterface;
use Interfaces\Web\Routing\PropertyModuleRouteContributor;
use Kernel\Module\Contract\ModuleConfigurationProvisionerInterface;
use Kernel\Module\Contract\RuleProvidingModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;

$catalog = new ModuleCatalog((new ModuleDiscovery($root . '/app/Domains'))->discover());
$property = $catalog->definition('property');
$contributions = $property->contributions;

if ($property->manifest->version !== '0.2.1') {
    throw new RuntimeException('Property runtime manifest must be V0.2.1.');
}
if ($contributions->runtimeModuleService !== 'propertyDomainModule') {
    throw new RuntimeException('Property must expose propertyDomainModule as runtime service.');
}
if ($contributions->apiRouteContributorServices !== ['propertyRouteContributor']) {
    throw new RuntimeException('Property must own its canonical API route contribution.');
}
if ($contributions->configurationProvisionerServices !== ['propertyModuleConfigurationProvisioner']) {
    throw new RuntimeException('Property must own its tenant configuration provisioning.');
}

$expectedCapabilities = [
    'property.registry',
    'property.read',
    'property.write',
    'property.intake',
    'property.media',
    'property.catalog',
];
if ($contributions->capabilities !== $expectedCapabilities) {
    throw new RuntimeException('Property capability contract drifted.');
}

if (!is_subclass_of(PropertyDomainModule::class, DomainModuleInterface::class)) {
    throw new RuntimeException('Property runtime module must implement DomainModuleInterface.');
}
if (!is_subclass_of(PropertyDomainModule::class, RuleProvidingModuleInterface::class)) {
    throw new RuntimeException('Property runtime must establish a configuration namespace through Kernel configuration provisioning.');
}
if ((new PropertyDomainModule())->name() !== 'property') {
    throw new RuntimeException('Property runtime module reports an invalid module id.');
}
if (!is_subclass_of(PropertyModuleConfigurationProvisioner::class, ModuleConfigurationProvisionerInterface::class)) {
    throw new RuntimeException('Property configuration provisioner must implement the Kernel contract.');
}
if (!is_subclass_of(PropertyModuleRouteContributor::class, ModuleRouteContributorInterface::class)) {
    throw new RuntimeException('Property route contributor must implement the Web module route contract.');
}

$services = (string) file_get_contents($root . '/app/config/services_kernel.php');
if (!str_contains($services, "Bootstrap/PropertyServices.php")) {
    throw new RuntimeException('Property runtime services are missing from the composition root.');
}

$webServices = (string) file_get_contents($root . '/app/Bootstrap/WebApplicationServices.php');
if (!str_contains($webServices, "setShared('propertyRouteContributor'")) {
    throw new RuntimeException('Property route contributor is not registered in Web composition.');
}

$routes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/PropertyRuntimeRoutes.php');
foreach (['/api/v1/property-registry', '/api/v1/property-registry/health'] as $route) {
    if (!str_contains($routes, $route)) {
        throw new RuntimeException('Property canonical runtime route is missing: ' . $route);
    }
}
if (str_contains($routes, '/api/v1/properties')) {
    throw new RuntimeException('Canonical Property runtime must not collapse into the legacy public catalog API.');
}

$controller = (string) file_get_contents($root . '/app/Interfaces/Api/Controller/PropertyRuntimeController.php');
foreach (['cosModuleReadinessDiagnostic', 'cosModuleCapabilityRegistry'] as $service) {
    if (!str_contains($controller, $service)) {
        throw new RuntimeException('Property runtime controller is missing Kernel service: ' . $service);
    }
}

echo "Property V0.2.1 runtime architecture: OK\n";
