<?php
declare(strict_types=1);

use Domains\Property\Infrastructure\ReadModel\MySql\MysqlPropertyAnalyticsReadModel;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesDemandReadModel;
use Infrastructure\Platform\Analytics\PropertyMarketAnalyticsService;

$di->setShared('propertyAnalyticsReadModel', fn (): MysqlPropertyAnalyticsReadModel => new MysqlPropertyAnalyticsReadModel(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesDemandReadModel', fn (): MysqlSalesDemandReadModel => new MysqlSalesDemandReadModel(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('propertyMarketAnalytics', fn (): PropertyMarketAnalyticsService => new PropertyMarketAnalyticsService(
    $this->getShared('propertyAnalyticsReadModel'),
    $this->getShared('salesDemandReadModel'),
    $this->getShared('organizationContext')->id(),
));
