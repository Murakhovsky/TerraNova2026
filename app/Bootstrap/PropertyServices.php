<?php
declare(strict_types=1);

use Domains\Property\Bootstrap\PropertyDomainModule;
use Domains\Property\Bootstrap\PropertyModuleConfigurationProvisioner;

$di->setShared('propertyDomainModule', fn (): PropertyDomainModule => new PropertyDomainModule());
$di->setShared('propertyModuleConfigurationProvisioner', fn (): PropertyModuleConfigurationProvisioner => new PropertyModuleConfigurationProvisioner(
    $this->getShared('cosConfigurationProvisioner'),
));
