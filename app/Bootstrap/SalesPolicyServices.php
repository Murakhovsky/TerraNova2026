<?php
declare(strict_types=1);
use Domains\Sales\Automation\Policy\SalesPolicyDefinitionCatalog;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesPolicyContextProvider;
use Infrastructure\Platform\Persistence\MySql\Configuration\MysqlSalesPolicyAdministration;
$di->setShared('salesPolicyContextProvider',fn():MysqlSalesPolicyContextProvider=>new MysqlSalesPolicyContextProvider($this->getShared('databaseService')->connection()));
$di->setShared('salesPolicyDefinitionCatalog',fn():SalesPolicyDefinitionCatalog=>new SalesPolicyDefinitionCatalog());
$di->setShared('salesPolicyAdministration',fn():MysqlSalesPolicyAdministration=>new MysqlSalesPolicyAdministration(
    $this->getShared('databaseService')->connection(),$this->getShared('salesPolicyDefinitionCatalog'),$this->getShared('cosDomainRegistry'),$this->getShared('cosPolicyRepository'),$this->getShared('cosPolicyContextBuilder'),$this->getShared('cosPolicyEngine')
));
