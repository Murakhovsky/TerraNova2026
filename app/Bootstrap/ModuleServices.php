<?php
declare(strict_types=1);

use Infrastructure\Module\MysqlModuleLifecycleRepository;
use Infrastructure\Module\MysqlModuleStateRepository;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleConfigurationProvisionerInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\EffectiveModuleContext;
use Kernel\Module\ModuleCapabilityRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleDiscovery;
use Kernel\Module\ModuleLifecycleManager;
use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Handler\ModuleAwareJobHandler;

$di->setShared('cosModuleDefinitions', fn (): array => (new ModuleDiscovery(
    APP_PATH . '/Domains',
))->discover());

$di->setShared('cosModuleCatalog', fn (): ModuleCatalog => new ModuleCatalog(
    $this->getShared('cosModuleDefinitions'),
));

$di->setShared('cosModuleStateRepository', fn (): MysqlModuleStateRepository => new MysqlModuleStateRepository(
    $this->getShared('databaseService'),
));

$di->setShared('cosModuleLifecycleRepository', fn (): MysqlModuleLifecycleRepository => new MysqlModuleLifecycleRepository(
    $this->getShared('databaseService'),
));

$di->setShared('cosActiveModuleResolver', fn (): ActiveModuleResolver => new ActiveModuleResolver(
    $this->getShared('cosModuleCatalog'),
    $this->getShared('cosModuleStateRepository'),
    $this->getShared('cosModuleLifecycleRepository'),
));

$di->setShared('cosModuleLifecycleManager', fn (): ModuleLifecycleManager => new ModuleLifecycleManager(
    $this->getShared('cosModuleCatalog'),
    $this->getShared('cosModuleStateRepository'),
    $this->getShared('cosModuleLifecycleRepository'),
));

$di->setShared('cosModuleCapabilityRegistry', fn (): ModuleCapabilityRegistry => new ModuleCapabilityRegistry(
    $this->getShared('cosModuleCatalog'),
));

$di->setShared('cosEffectiveModuleContext', fn (): EffectiveModuleContext => new EffectiveModuleContext(
    $this->getShared('cosActiveModuleResolver'),
    $this->getShared('cosModuleCapabilityRegistry'),
));

// Runtime contributors are declared by each domain's module.php and resolved generically.
$di->setShared('cosInstalledDomainModules', function (): array {
    $modules = [];

    /** @var ModuleDefinition $definition */
    foreach ($this->getShared('cosModuleDefinitions') as $definition) {
        $serviceId = $definition->contributions->runtimeModuleService;
        if ($serviceId === null) {
            continue;
        }

        $module = $this->getShared($serviceId);
        if (!$module instanceof DomainModuleInterface) {
            throw new RuntimeException(sprintf(
                'Module %s runtime service %s must implement DomainModuleInterface.',
                $definition->manifest->id,
                $serviceId,
            ));
        }
        if ($module->name() !== $definition->manifest->id) {
            throw new RuntimeException(sprintf(
                'Module %s runtime service reports mismatched id %s.',
                $definition->manifest->id,
                $module->name(),
            ));
        }

        $modules[] = $module;
    }

    return $modules;
});
$di->setShared('cosDomainModules', fn (): array => $this->getShared('cosInstalledDomainModules'));

// Queue handlers are automatically wrapped in the module activation gate.
$di->setShared('cosModuleJobHandlers', function (): array {
    $handlers = [];

    /** @var ModuleDefinition $definition */
    foreach ($this->getShared('cosModuleDefinitions') as $definition) {
        foreach ($definition->contributions->jobHandlerServices as $serviceId) {
            $handler = $this->getShared($serviceId);
            if (!$handler instanceof JobHandlerInterface) {
                throw new RuntimeException(sprintf(
                    'Module %s job service %s must implement JobHandlerInterface.',
                    $definition->manifest->id,
                    $serviceId,
                ));
            }

            $handlers[] = new ModuleAwareJobHandler(
                $definition->manifest->id,
                $handler,
                $this->getShared('cosActiveModuleResolver'),
            );
        }
    }

    return $handlers;
});

// API integrations remain declarative and retain module ownership.
$di->setShared('cosModuleApiRouteContributors', function (): array {
    $contributors = [];
    foreach ($this->getShared('cosModuleDefinitions') as $definition) {
        foreach ($definition->contributions->apiRouteContributorServices as $serviceId) {
            $contributors[] = [
                'module_id' => $definition->manifest->id,
                'service' => $this->getShared($serviceId),
            ];
        }
    }

    return $contributors;
});

// Tenant configuration is provisioned only through services explicitly owned by a module manifest.
$di->setShared('cosModuleConfigurationProvisioners', function (): array {
    $provisioners = [];
    foreach ($this->getShared('cosModuleDefinitions') as $definition) {
        foreach ($definition->contributions->configurationProvisionerServices as $serviceId) {
            $service = $this->getShared($serviceId);
            if (!$service instanceof ModuleConfigurationProvisionerInterface) {
                throw new RuntimeException(sprintf(
                    'Module %s configuration service %s must implement ModuleConfigurationProvisionerInterface.',
                    $definition->manifest->id,
                    $serviceId,
                ));
            }

            $provisioners[] = [
                'module_id' => $definition->manifest->id,
                'service' => $service,
            ];
        }
    }

    return $provisioners;
});
