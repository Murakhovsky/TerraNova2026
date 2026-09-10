<?php
declare(strict_types=1);

use Bootstrap\InboundCaseResolverAdapter;
use Domains\Sales\Application\Service\ClientCaseCommandService;
use Domains\Sales\Application\Service\SalesInboundService;
use Domains\Sales\Application\Service\SalesMonitoringService;
use Domains\Sales\Application\Service\SalesOperationService;
use Domains\Sales\Application\UseCase\AssignDealOwner;
use Domains\Sales\Application\UseCase\ChangeDealStage;
use Domains\Sales\Application\UseCase\CompleteSalesCall;
use Domains\Sales\Application\UseCase\ProcessCrmInbox;
use Domains\Sales\Application\UseCase\ReceiveCrmWebhook;
use Domains\Sales\Application\UseCase\ReceivePublicLead;
use Domains\Sales\Application\UseCase\ScheduleDealFollowup;
use Domains\Sales\Automation\Job\CrmInboxJobHandler;
use Domains\Sales\Bootstrap\SalesDomainModule;
use Domains\Sales\Domain\Policy\StageTransitionPolicy;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlClientCaseCommandRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlDealRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlInboundLeadRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlPipelineRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAttentionRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOperationRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOutcomeRepository;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlClientCaseReadModel;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesWorkspaceOperationalReadModel;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesWorkspaceReadModel;

$di->setShared('salesDomainModule', fn (): SalesDomainModule => new SalesDomainModule(
    $this->getShared('cosCrmGateway'),
    $this->getShared('salesDealRepository'),
    $this->getShared('salesScheduleDealFollowup'),
    $this->getShared('salesRuleContextProvider'),
    $this->getShared('salesAgentContextBuilder'),
    $this->getShared('salesChangeDealStage'),
    $this->getShared('salesOperationService'),
    $this->getShared('salesAssignDealOwner'),
));

$di->setShared('salesCompleteCall', fn (): CompleteSalesCall => new CompleteSalesCall(
    $this->getShared('salesActivityRepository'), $this->getShared('eventBus'), $this->getShared('cosTransactionManager'),
));
$di->setShared('salesClientCaseReadModel', fn (): MysqlClientCaseReadModel => new MysqlClientCaseReadModel(
    $this->getShared('databaseService')->connection(), $this->getShared('organizationContext')->id(),
));
$di->setShared('salesWorkspaceReadModel', fn (): MysqlSalesWorkspaceReadModel => new MysqlSalesWorkspaceReadModel(
    $this->getShared('databaseService')->connection(),
));
// The composition root owns the concrete projection; Web/API layers consume only the Application contract.
$di->setShared('salesWorkspaceOperationalReadModel', fn (): MysqlSalesWorkspaceOperationalReadModel => new MysqlSalesWorkspaceOperationalReadModel(
    $this->getShared('databaseService')->connection(),
    $this->getShared('salesWorkspaceReadModel'),
));
$di->setShared('salesOutcomeRepository', fn (): MysqlSalesOutcomeRepository => new MysqlSalesOutcomeRepository($this->getShared('databaseService')->connection()));
$di->setShared('salesClientCaseCommands', fn (): MysqlClientCaseCommandRepository => new MysqlClientCaseCommandRepository($this->getShared('databaseService')->connection()));
$di->setShared('salesPipelineRepository', fn (): MysqlPipelineRepository => new MysqlPipelineRepository($this->getShared('databaseService')->connection()));
$di->setShared('salesDealRepository', fn (): MysqlDealRepository => new MysqlDealRepository($this->getShared('databaseService')->connection()));
$di->setShared('salesOperationRepository', fn (): MysqlSalesOperationRepository => new MysqlSalesOperationRepository($this->getShared('databaseService')->connection()));
$di->setShared('salesAttentionRepository', fn (): MysqlSalesAttentionRepository => new MysqlSalesAttentionRepository($this->getShared('databaseService')->connection()));

$di->setShared('salesChangeDealStage', fn (): ChangeDealStage => new ChangeDealStage(
    $this->getShared('salesDealRepository'), $this->getShared('salesPipelineRepository'), new StageTransitionPolicy(),
    $this->getShared('eventBus'), $this->getShared('cosTransactionManager'),
));
$di->setShared('salesAssignDealOwner', fn (): AssignDealOwner => new AssignDealOwner(
    $this->getShared('salesDealRepository'), $this->getShared('eventBus'), $this->getShared('cosTransactionManager'),
));
$di->setShared('salesScheduleDealFollowup', fn (): ScheduleDealFollowup => new ScheduleDealFollowup(
    $this->getShared('salesFollowupRepository'), $this->getShared('eventBus'), $this->getShared('cosTransactionManager'),
));
$di->setShared('salesOperationService', fn (): SalesOperationService => new SalesOperationService(
    $this->getShared('cosCrmGateway'), $this->getShared('salesOperationRepository'),
    $this->getShared('eventBus'), $this->getShared('cosTransactionManager'),
));
$di->setShared('salesMonitoringService', fn (): SalesMonitoringService => new SalesMonitoringService(
    $this->getShared('salesAttentionRepository'), $this->getShared('salesOutcomeRepository'),
    $this->getShared('eventBus'), $this->getShared('cosTransactionManager'),
));
$di->setShared('salesClientCaseService', fn (): ClientCaseCommandService => new ClientCaseCommandService(
    $this->getShared('salesClientCaseReadModel'), $this->getShared('salesClientCaseCommands'),
    $this->getShared('eventBus'), $this->getShared('cosTransactionManager'), $this->getShared('organizationContext')->id(),
    $this->getShared('salesCompleteCall'), $this->getShared('salesPipelineRepository'),
    $this->getShared('salesChangeDealStage'), $this->getShared('salesAssignDealOwner'),
));
$di->setShared('salesInboundService', fn (): SalesInboundService => new SalesInboundService(
    $this->getShared('salesClientCaseReadModel'), $this->getShared('salesClientCaseCommands'),
    $this->getShared('eventBus'), $this->getShared('cosTransactionManager'), $this->getShared('organizationContext')->id(),
    $this->getShared('salesPipelineRepository'), $this->getShared('salesChangeDealStage'),
));

$di->setShared('salesInboundLeadRepository', fn (): MysqlInboundLeadRepository => new MysqlInboundLeadRepository($this->getShared('databaseService')->connection()));
$di->setShared('salesInboundCaseResolver', fn (): InboundCaseResolverAdapter => new InboundCaseResolverAdapter($this->getShared('salesInboundService')));
$di->setShared('salesReceivePublicLead', fn (): ReceivePublicLead => new ReceivePublicLead(
    $this->getShared('salesInboundLeadRepository'), $this->getShared('salesInboundCaseResolver'),
    $this->getShared('eventBus'), $this->getShared('cosTransactionManager'), $this->getShared('organizationContext')->id(),
));
$di->setShared('salesReceiveCrmWebhook', fn (): ReceiveCrmWebhook => new ReceiveCrmWebhook(
    $this->getShared('cosCrmWebhookSecrets'), $this->getShared('cosCrmInbox'),
    $this->getShared('cosJobQueue'), $this->getShared('cosTransactionManager'),
));
$di->setShared('salesProcessCrmInbox', fn (): ProcessCrmInbox => new ProcessCrmInbox(
    $this->getShared('cosCrmInbox'), $this->getShared('cosCrmInboundApplier'), $this->getShared('cosDomainRegistry'),
    $this->getShared('eventBus'), $this->getShared('cosTransactionManager'),
    $this->getShared('salesPipelineRepository'), $this->getShared('salesChangeDealStage'), $this->getShared('salesOperationService'),
));
$di->setShared('salesCrmInboxJobHandler', fn (): CrmInboxJobHandler => new CrmInboxJobHandler($this->getShared('salesProcessCrmInbox')));
