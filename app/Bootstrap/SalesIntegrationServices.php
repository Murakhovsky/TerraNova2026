<?php
declare(strict_types=1);

use Domains\Sales\Automation\Integration\SalesIntegrationDefinitionCatalog;
use Infrastructure\Integration\Crm\CrmRegistrySalesIntegrationHealthProbe;
use Infrastructure\Platform\Persistence\MySql\Configuration\MysqlSalesIntegrationAdministration;

$di->setShared('salesIntegrationHealthProbe', fn (): CrmRegistrySalesIntegrationHealthProbe => new CrmRegistrySalesIntegrationHealthProbe(
    $this->getShared('cosCrmRegistry'),
));
$di->setShared('salesIntegrationDefinitionCatalog', fn (): SalesIntegrationDefinitionCatalog => new SalesIntegrationDefinitionCatalog());
$di->setShared('salesIntegrationAdministration', fn (): MysqlSalesIntegrationAdministration => new MysqlSalesIntegrationAdministration(
    $this->getShared('databaseService')->connection(),
    $this->getShared('salesIntegrationHealthProbe'),
    $this->getShared('salesIntegrationDefinitionCatalog'),
));
