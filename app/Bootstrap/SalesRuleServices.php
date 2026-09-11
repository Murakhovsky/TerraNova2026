<?php
declare(strict_types=1);

use Domains\Sales\Automation\Rule\SalesRuleDefinitionCatalog;
use Infrastructure\Platform\Persistence\MySql\Configuration\MysqlSalesRuleAdministration;

$di->setShared('salesRuleDefinitionCatalog', fn (): SalesRuleDefinitionCatalog => new SalesRuleDefinitionCatalog());
$di->setShared('salesRuleAdministration', fn (): MysqlSalesRuleAdministration => new MysqlSalesRuleAdministration(
    $this->getShared('databaseService')->connection(),
    $this->getShared('salesRuleDefinitionCatalog'),
    $this->getShared('cosDomainRegistry'),
    $this->getShared('cosConditionEvaluator'),
    $this->getShared('cosPolicyRepository'),
    $this->getShared('cosPolicyEngine'),
));
