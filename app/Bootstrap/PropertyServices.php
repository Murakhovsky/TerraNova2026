<?php
declare(strict_types=1);

use Domains\Property\Application\Service\PropertyCanonicalRuntimeService;
use Domains\Property\Bootstrap\PropertyDomainModule;
use Domains\Property\Bootstrap\PropertyModuleConfigurationProvisioner;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyCanonicalRuntimeRepository;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyCompatibilityProjection;
use Domains\Property\Infrastructure\ReadModel\MySql\MysqlPropertyReferencePort;
use Domains\Property\Rule\PropertyRuleContextProvider;

$di->setShared('propertyRuleContextProvider', fn (): PropertyRuleContextProvider => new PropertyRuleContextProvider());
$di->setShared('propertyReferencePort', fn (): MysqlPropertyReferencePort => new MysqlPropertyReferencePort($this->getShared('databaseService')->connection()));
$di->setShared('propertyCanonicalRuntimeRepository', fn (): MysqlPropertyCanonicalRuntimeRepository => new MysqlPropertyCanonicalRuntimeRepository($this->getShared('databaseService')->connection()));
$di->setShared('propertyCompatibilityProjection', fn (): MysqlPropertyCompatibilityProjection => new MysqlPropertyCompatibilityProjection($this->getShared('databaseService')->connection()));
$di->setShared('propertyCanonicalRuntime', fn (): PropertyCanonicalRuntimeService => new PropertyCanonicalRuntimeService(
    $this->getShared('propertyCanonicalRuntimeRepository'),
    $this->getShared('propertyCompatibilityProjection'),
    $this->getShared('eventBus'),
    $this->getShared('cosTransactionManager'),
));
$di->setShared('propertyDomainModule', fn (): PropertyDomainModule => new PropertyDomainModule($this->getShared('propertyRuleContextProvider')));
$di->setShared('propertyModuleConfigurationProvisioner', fn (): PropertyModuleConfigurationProvisioner => new PropertyModuleConfigurationProvisioner($this->getShared('cosConfigurationProvisioner')));
