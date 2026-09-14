<?php
declare(strict_types=1);

use Domains\Sales\Application\Contract\CrmIngressResolverInterface;
use Domains\Sales\Automation\Integration\SalesIntegrationDefinitionCatalog;
use Infrastructure\Integration\Crm\CrmRegistrySalesIntegrationHealthProbe;
use Infrastructure\Integration\Crm\MysqlCrmIngressResolver;
use Infrastructure\Platform\Persistence\MySql\Configuration\MysqlSalesIntegrationAdministration;

$di->setShared('salesCrmIngressResolver', fn (): CrmIngressResolverInterface => new MysqlCrmIngressResolver(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesIntegrationHealthProbe', fn (): CrmRegistrySalesIntegrationHealthProbe => new CrmRegistrySalesIntegrationHealthProbe(
    $this->getShared('cosCrmRegistry'),
));
$di->setShared('salesIntegrationDefinitionCatalog', fn (): SalesIntegrationDefinitionCatalog => new SalesIntegrationDefinitionCatalog());
$di->setShared('salesIntegrationAdministration', fn (): MysqlSalesIntegrationAdministration => new MysqlSalesIntegrationAdministration(
    $this->getShared('databaseService')->connection(),
    $this->getShared('salesIntegrationHealthProbe'),
    $this->getShared('salesIntegrationDefinitionCatalog'),
));
