<?php
declare(strict_types=1);

use Domains\Property\Application\Service\PropertyNetworkConnectorRegistry;
use Domains\Property\Application\Service\PropertyNetworkSyncService;
use Domains\Property\Infrastructure\Network\PropertyNetworkIntakeAdapter;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyNetworkSyncRepository;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertySubmissionRepository;
use Domains\Property\Infrastructure\ReadModel\MySql\MysqlPropertyNetworkExportReadModel;

$di->setShared('propertyNetworkConnectorRegistry', fn (): PropertyNetworkConnectorRegistry => new PropertyNetworkConnectorRegistry());
$di->setShared('propertyNetworkSyncRepository', fn (): MysqlPropertyNetworkSyncRepository => new MysqlPropertyNetworkSyncRepository(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('propertyNetworkSubmissionRepository', fn (): MysqlPropertySubmissionRepository => new MysqlPropertySubmissionRepository(
    $this->getShared('databaseService'),
    $this->getShared('organizationContext')->id(),
));
$di->setShared('propertyNetworkIntake', fn (): PropertyNetworkIntakeAdapter => new PropertyNetworkIntakeAdapter(
    $this->getShared('propertyNetworkSubmissionRepository'),
));
$di->setShared('propertyNetworkExport', fn (): MysqlPropertyNetworkExportReadModel => new MysqlPropertyNetworkExportReadModel(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('propertyNetworkSync', fn (): PropertyNetworkSyncService => new PropertyNetworkSyncService(
    $this->getShared('propertyNetworkConnectorRegistry'),
    $this->getShared('propertyNetworkSyncRepository'),
    $this->getShared('propertyNetworkIntake'),
    $this->getShared('propertyNetworkExport'),
));
