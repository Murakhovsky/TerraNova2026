<?php
declare(strict_types=1);

use Domains\Property\Application\Service\PropertyIdentityWorkflowService;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyIdentityWorkflowRepository;

$di->setShared('propertyIdentityWorkflowRepository', fn (): MysqlPropertyIdentityWorkflowRepository => new MysqlPropertyIdentityWorkflowRepository(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('propertyIdentityWorkflow', fn (): PropertyIdentityWorkflowService => new PropertyIdentityWorkflowService(
    $this->getShared('propertyIdentityWorkflowRepository'),
));
