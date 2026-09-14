<?php
declare(strict_types=1);

use Domains\Property\Bootstrap\PropertyDomainModule;
use Domains\Property\Bootstrap\PropertyModuleConfigurationProvisioner;
use Domains\Property\Rule\PropertyRuleContextProvider;

$di->setShared('propertyRuleContextProvider', fn (): PropertyRuleContextProvider => new PropertyRuleContextProvider());
$di->setShared('propertyDomainModule', fn (): PropertyDomainModule => new PropertyDomainModule(
    $this->getShared('propertyRuleContextProvider'),
));
$di->setShared('propertyModuleConfigurationProvisioner', fn (): PropertyModuleConfigurationProvisioner => new PropertyModuleConfigurationProvisioner(
    $this->getShared('cosConfigurationProvisioner'),
));
