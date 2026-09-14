<?php
declare(strict_types=1);

use Domains\Sales\Application\Service\SalesDealOwnerHistoryProjector;
use Domains\Sales\Application\Service\SalesDealOwnerHistoryRebuilder;
use Domains\Sales\Application\Service\SalesDealStageHistoryProjector;
use Domains\Sales\Application\Service\SalesDealStageHistoryRebuilder;
use Domains\Sales\Application\Service\SalesDirectorCockpitService;
use Domains\Sales\Application\Service\SalesForecastRiskService;
use Domains\Sales\Application\Service\SalesHistoricalIntelligenceHealthService;
use Domains\Sales\Application\Service\SalesHistoricalMetricsService;
use Domains\Sales\Application\Service\SalesMetricDictionary;
use Domains\Sales\Application\Service\SalesOperationalPerformanceService;
use Domains\Sales\Automation\Event\SalesHistoricalEventConsumer;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesDealOwnerHistoryStore;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesDealStageHistoryStore;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesHistoricalEventStream;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesMetricConfiguration;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesDealStageHistoryReadModel;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesForecastRiskReadModel;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesHistoricalIntelligenceHealthReadModel;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesHistoricalMetricsReadModel;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesOperationalPerformanceReadModel;

$di->setShared('salesDealStageHistoryStore', fn (): MysqlSalesDealStageHistoryStore => new MysqlSalesDealStageHistoryStore(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesDealOwnerHistoryStore', fn (): MysqlSalesDealOwnerHistoryStore => new MysqlSalesDealOwnerHistoryStore(
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
$di->setShared('salesOperationalPerformanceReadModel', fn (): MysqlSalesOperationalPerformanceReadModel => new MysqlSalesOperationalPerformanceReadModel(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesForecastRiskReadModel', fn (): MysqlSalesForecastRiskReadModel => new MysqlSalesForecastRiskReadModel(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesHistoricalIntelligenceHealthReadModel', fn (): MysqlSalesHistoricalIntelligenceHealthReadModel => new MysqlSalesHistoricalIntelligenceHealthReadModel(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesMetricConfiguration', fn (): MysqlSalesMetricConfiguration => new MysqlSalesMetricConfiguration(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesMetricDictionary', fn (): SalesMetricDictionary => new SalesMetricDictionary());
$di->setShared('salesHistoricalMetrics', fn (): SalesHistoricalMetricsService => new SalesHistoricalMetricsService(
    $this->getShared('salesHistoricalMetricsReadModel'),
));
$di->setShared('salesOperationalPerformance', fn (): SalesOperationalPerformanceService => new SalesOperationalPerformanceService(
    $this->getShared('salesOperationalPerformanceReadModel'),
));
$di->setShared('salesForecastRisk', fn (): SalesForecastRiskService => new SalesForecastRiskService(
    $this->getShared('salesForecastRiskReadModel'),
));
$di->setShared('salesHistoricalIntelligenceHealth', fn (): SalesHistoricalIntelligenceHealthService => new SalesHistoricalIntelligenceHealthService(
    $this->getShared('salesHistoricalIntelligenceHealthReadModel'),
));
$di->setShared('salesDirectorCockpit', fn (): SalesDirectorCockpitService => new SalesDirectorCockpitService(
    $this->getShared('salesHistoricalMetrics'),
    $this->getShared('salesOperationalPerformance'),
    $this->getShared('salesForecastRisk'),
));
$di->setShared('salesDealStageHistoryProjector', fn (): SalesDealStageHistoryProjector => new SalesDealStageHistoryProjector(
    $this->getShared('salesDealStageHistoryStore'),
));
$di->setShared('salesDealOwnerHistoryProjector', fn (): SalesDealOwnerHistoryProjector => new SalesDealOwnerHistoryProjector(
    $this->getShared('salesDealOwnerHistoryStore'),
));
$di->setShared('salesDealStageHistoryRebuilder', fn (): SalesDealStageHistoryRebuilder => new SalesDealStageHistoryRebuilder(
    $this->getShared('salesHistoricalEventStream'),
    $this->getShared('salesDealStageHistoryProjector'),
    $this->getShared('salesDealStageHistoryStore'),
    $this->getShared('cosTransactionManager'),
));
$di->setShared('salesDealOwnerHistoryRebuilder', fn (): SalesDealOwnerHistoryRebuilder => new SalesDealOwnerHistoryRebuilder(
    $this->getShared('salesHistoricalEventStream'),
    $this->getShared('salesDealOwnerHistoryProjector'),
    $this->getShared('salesDealOwnerHistoryStore'),
    $this->getShared('cosTransactionManager'),
));
$di->setShared('salesHistoricalEventConsumer', fn (): SalesHistoricalEventConsumer => new SalesHistoricalEventConsumer(
    $this->getShared('salesDealStageHistoryProjector'),
    $this->getShared('salesDealOwnerHistoryProjector'),
    $this->getShared('salesDealOwnerHistoryStore'),
    $this->getShared('salesPipelineRepository'),
    $this->getShared('eventBus'),
    $this->getShared('eventStore'),
));
