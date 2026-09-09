<?php
declare(strict_types=1);

use Infrastructure\Module\MysqlModuleStateRepository;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;
use Kernel\Queue\Handler\ModuleAwareJobHandler;

$di->setShared('cosModuleCatalog', function (): ModuleCatalog {
    $manifests = [];
    foreach ([
        APP_PATH . '/Domains/Sales/module.php',
        APP_PATH . '/Domains/Diagnostic/module.php',
        APP_PATH . '/Domains/Property/module.php',
    ] as $path) {
        $definition = require $path;
        if (!is_array($definition)) {
            throw new \RuntimeException(sprintf('Invalid module manifest: %s.', $path));
        }
        $manifests[] = ModuleManifest::fromArray($definition);
    }

    return new ModuleCatalog($manifests);
});

$di->setShared('cosModuleStateRepository', fn (): MysqlModuleStateRepository => new MysqlModuleStateRepository(
    $this->getShared('databaseService'),
));

$di->setShared('cosActiveModuleResolver', fn (): ActiveModuleResolver => new ActiveModuleResolver(
    $this->getShared('cosModuleCatalog'),
    $this->getShared('cosModuleStateRepository'),
));

// Installed runtime contributors are composed here, not inside KernelServices.
$di->setShared('cosInstalledDomainModules', fn (): array => [
    $this->getShared('salesDomainModule'),
]);
$di->setShared('cosDomainModules', fn (): array => $this->getShared('cosInstalledDomainModules'));

// Domain-owned queue handlers are wrapped in a tenant-aware activation gate.
$di->setShared('cosModuleJobHandlers', fn (): array => [
    new ModuleAwareJobHandler(
        'sales',
        $this->getShared('salesCrmInboxJobHandler'),
        $this->getShared('cosActiveModuleResolver'),
    ),
]);
