<?php
declare(strict_types=1);

use Infrastructure\Module\MysqlModuleLifecycleRepository;
use Infrastructure\Module\MysqlModuleStateRepository;
use Kernel\Event\Contract\DurableEventConsumerInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleConfigurationProvisionerInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\EffectiveModuleContext;
use Kernel\Module\ModuleCapabilityRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleDiscovery;
use Kernel\Module\ModuleExtensionRegistry;
use Kernel\Module\ModuleLifecycleManager;
use Kernel\Module\ModuleReadinessDiagnostic;
use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Handler\ModuleAwareJobHandler;

$di->setShared('cosModuleDefinitions', fn (): array => (new ModuleDiscovery(
    APP_PATH . '/Domains',
    dirname(APP_PATH) . '/tmp/cache/cos_modules.php',
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

$di->setShared('cosModuleExtensionRegistry', fn (): ModuleExtensionRegistry => new ModuleExtensionRegistry(
    $this->getShared('cosModuleCatalog'),
));

$di->setShared('cosEffectiveModuleContext', fn (): EffectiveModuleContext => new EffectiveModuleContext(
    $this->getShared('cosActiveModuleResolver'),
    $this->getShared('cosModuleCapabilityRegistry'),
));

$di->setShared('cosModuleReadinessDiagnostic', fn (): ModuleReadinessDiagnostic => new ModuleReadinessDiagnostic(
    $this->getShared('cosModuleCatalog'),
    $this->getShared('cosActiveModuleResolver'),
    $this->getShared('cosMigrationRunner'),
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

// Durable event consumers are resolved through the same generic extension runtime.
// The stable consumer name is owned by the consumer contract because the event
// consumption repository uses it as the idempotency boundary.
$di->setShared('cosModuleEventConsumers', function (): array {
    $consumers = [];
    /** @var ModuleExtensionRegistry $registry */
    $registry = $this->getShared('cosModuleExtensionRegistry');
    foreach ($registry->for(ModuleExtensionRegistry::EVENT_CONSUMERS) as $extension) {
        $consumer = $this->getShared($extension->serviceId);
        if (!$consumer instanceof DurableEventConsumerInterface) {
            throw new RuntimeException(sprintf(
                'Module %s event consumer service %s must implement DurableEventConsumerInterface.',
                $extension->moduleId,
                $extension->serviceId,
            ));
        }

        $name = $consumer->consumerName();
        if (str_starts_with($name, 'kernel.')) {
            throw new RuntimeException(sprintf(
                'Module %s event consumer %s uses reserved kernel.* namespace.',
                $extension->moduleId,
                $name,
            ));
        }
        if (isset($consumers[$name])) {
            throw new RuntimeException(sprintf('Duplicate durable event consumer name: %s.', $name));
        }
        $consumers[$name] = $consumer;
    }

    return $consumers;
});

// API routes are a first-class extension point. Routes remain globally registered;
// request-time module access is enforced by ModuleRouteAccessGuard in the Web layer.
$di->setShared('cosModuleApiRouteContributors', function (): array {
    $contributors = [];
    /** @var ModuleExtensionRegistry $registry */
    $registry = $this->getShared('cosModuleExtensionRegistry');
    foreach ($registry->for(ModuleExtensionRegistry::API_ROUTES) as $extension) {
        $contributors[] = [
            'module_id' => $extension->moduleId,
            'service' => $this->getShared($extension->serviceId),
        ];
    }

    return $contributors;
});

// Tenant configuration provisioning uses the same extension registry while retaining
// its explicit contract and ownership validation.
$di->setShared('cosModuleConfigurationProvisioners', function (): array {
    $provisioners = [];
    /** @var ModuleExtensionRegistry $registry */
    $registry = $this->getShared('cosModuleExtensionRegistry');
    foreach ($registry->for(ModuleExtensionRegistry::TENANT_CONFIGURATION) as $extension) {
        $service = $this->getShared($extension->serviceId);
        if (!$service instanceof ModuleConfigurationProvisionerInterface) {
            throw new RuntimeException(sprintf(
                'Module %s configuration service %s must implement ModuleConfigurationProvisionerInterface.',
                $extension->moduleId,
                $extension->serviceId,
            ));
        }

        $provisioners[] = [
            'module_id' => $extension->moduleId,
            'service' => $service,
        ];
    }

    return $provisioners;
});

// Web UI contributions stay provider-neutral in Kernel. The Web layer validates
// the concrete navigation contract when it consumes this extension point.
$di->setShared('cosModuleWebNavigationContributors', function (): array {
    $contributors = [];
    /** @var ModuleExtensionRegistry $registry */
    $registry = $this->getShared('cosModuleExtensionRegistry');
    foreach ($registry->for('web.navigation') as $extension) {
        $contributors[] = [
            'module_id' => $extension->moduleId,
            'service' => $this->getShared($extension->serviceId),
        ];
    }

    return $contributors;
});
