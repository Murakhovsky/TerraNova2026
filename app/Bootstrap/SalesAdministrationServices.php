<?php
declare(strict_types=1);

use Domains\Sales\Application\Service\SalesAdministrationHealthClassifier;
use Infrastructure\Platform\ReadModel\MySql\MysqlSalesAdministrationReadModel;

$di->setShared('salesAdministrationHealthClassifier', fn (): SalesAdministrationHealthClassifier => new SalesAdministrationHealthClassifier());
$di->setShared('salesAdministrationReadModel', fn (): MysqlSalesAdministrationReadModel => new MysqlSalesAdministrationReadModel(
    $this->getShared('databaseService')->connection(),
    $this->getShared('salesAdministrationHealthClassifier'),
));
