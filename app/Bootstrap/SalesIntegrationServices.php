<?php
declare(strict_types=1);

use Domains\Sales\Automation\Integration\SalesIntegrationDefinitionCatalog;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesIntegrationAdministration;
use Infrastructure\Integration\Crm\CrmRegistrySalesIntegrationHealthProbe;

$di->setShared('salesIntegrationHealthProbe', fn (): CrmRegistrySalesIntegrationHealthProbe => new CrmRegistrySalesIntegrationHealthProbe(
    $this->getShared('cosCrmRegistry'),
));
$di->setShared('salesIntegrationDefinitionCatalog', fn (): SalesIntegrationDefinitionCatalog => new SalesIntegrationDefinitionCatalog());
$di->setShared('salesIntegrationAdministration', fn (): MysqlSalesIntegrationAdministration => new MysqlSalesIntegrationAdministration(
    $this->getShared('databaseService')->connection(),
    $this->getShared('salesIntegrationHealthProbe'),
    $this->getShared('salesIntegrationDefinitionCatalog'),
));
