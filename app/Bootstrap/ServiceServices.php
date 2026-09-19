<?php
declare(strict_types=1);

use Domains\Service\Application\Service\ServiceWorkflowService;
use Domains\Service\Bootstrap\ServiceDomainModule;
use Domains\Service\Infrastructure\Persistence\MySql\MysqlServiceMutationReceipt;
use Domains\Service\Infrastructure\Persistence\MySql\MysqlServiceRepository;

$di->setShared('serviceRepository', fn (): MysqlServiceRepository => new MysqlServiceRepository(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('serviceMutationReceipt', fn (): MysqlServiceMutationReceipt => new MysqlServiceMutationReceipt(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('serviceWorkflow', fn (): ServiceWorkflowService => new ServiceWorkflowService(
    $this->getShared('serviceRepository'),
    $this->getShared('serviceMutationReceipt'),
    $this->getShared('eventBus'),
    $this->getShared('cosTransactionManager'),
    $this->getShared('cosAuditRepository'),
));
$di->setShared('serviceDomainModule', fn (): ServiceDomainModule => new ServiceDomainModule());
