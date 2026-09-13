<?php
declare(strict_types=1);

use Domains\Sales\Application\Service\SalesDealStageHistoryProjector;
use Domains\Sales\Application\Service\SalesDealStageHistoryRebuilder;
use Domains\Sales\Application\Service\SalesHistoricalMetricsService;
use Domains\Sales\Application\Service\SalesMetricDictionary;
use Domains\Sales\Automation\Event\SalesHistoricalEventConsumer;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesDealStageHistoryStore;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesHistoricalEventStream;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesMetricConfiguration;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesDealStageHistoryReadModel;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesHistoricalMetricsReadModel;

$di->setShared('salesDealStageHistoryStore', fn (): MysqlSalesDealStageHistoryStore => new MysqlSalesDealStageHistoryStore(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesHistoricalEventStream', fn (): MysqlSalesHistoricalEventStream => new MysqlSalesHistoricalEventStream(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesDealStageHistoryReadModel', fn (): MysqlSalesDealStageHistoryReadModel => new MysqlSalesDealStageHistoryReadModel(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesHistoricalMetricsReadModel', fn (): MysqlSalesHistoricalMetricsReadModel => new MysqlSalesHistoricalMetricsReadModel(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesMetricConfiguration', fn (): MysqlSalesMetricConfiguration => new MysqlSalesMetricConfiguration(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesMetricDictionary', fn (): SalesMetricDictionary => new SalesMetricDictionary());
$di->setShared('salesHistoricalMetrics', fn (): SalesHistoricalMetricsService => new SalesHistoricalMetricsService(
    $this->getShared('salesHistoricalMetricsReadModel'),
));
$di->setShared('salesDealStageHistoryProjector', fn (): SalesDealStageHistoryProjector => new SalesDealStageHistoryProjector(
    $this->getShared('salesDealStageHistoryStore'),
));
$di->setShared('salesDealStageHistoryRebuilder', fn (): SalesDealStageHistoryRebuilder => new SalesDealStageHistoryRebuilder(
    $this->getShared('salesHistoricalEventStream'),
    $this->getShared('salesDealStageHistoryProjector'),
    $this->getShared('salesDealStageHistoryStore'),
    $this->getShared('cosTransactionManager'),
));
$di->setShared('salesHistoricalEventConsumer', fn (): SalesHistoricalEventConsumer => new SalesHistoricalEventConsumer(
    $this->getShared('salesDealStageHistoryProjector'),
    $this->getShared('salesPipelineRepository'),
    $this->getShared('eventBus'),
    $this->getShared('eventStore'),
));
