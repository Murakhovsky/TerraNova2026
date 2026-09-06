<?php
declare(strict_types=1);

use Domains\Sales\Bootstrap\SalesDomainModule;
use Domains\Sales\Application\UseCase\AddClientCaseActivity;
use Domains\Sales\Application\UseCase\AddClientCasePropertyMatch;
use Domains\Sales\Application\UseCase\AttachInboundRequest;
use Domains\Sales\Application\UseCase\CompleteSalesCall;
use Domains\Sales\Application\UseCase\CreateClientCase;
use Domains\Sales\Application\UseCase\CreateClientCaseFromInboundRequest;
use Domains\Sales\Application\UseCase\EnsureInboundClientCase;
use Domains\Sales\Application\UseCase\ProcessCrmInbox;
use Domains\Sales\Application\UseCase\QuickUpdateClientCase;
use Domains\Sales\Application\UseCase\ReceiveCrmWebhook;
use Domains\Sales\Application\UseCase\ReceivePublicLead;
use Domains\Sales\Application\UseCase\RegisterInboundClientCaseRequest;
use Domains\Sales\Application\UseCase\ResolveInboundProperty;
use Domains\Sales\Application\UseCase\UpdateClientCase;
use Domains\Sales\Application\UseCase\UpdateClientCasePropertyMatch;
use Domains\Sales\Application\UseCase\UpdateInboundClientCaseRequest;
use Domains\Sales\Automation\Job\CrmInboxJobHandler;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlClientCaseCommandRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlInboundLeadRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOutcomeRepository;
use Domains\Sales\Application\UseCase\RecordActionOutcome;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlClientCaseReadModel;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesWorkspaceReadModel;
use Bootstrap\InboundCaseResolverAdapter;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlPipelineRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlDealRepository;
use Domains\Sales\Application\UseCase\ChangeDealStage;
use Domains\Sales\Domain\Policy\StageTransitionPolicy;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOperationRepository;
use Domains\Sales\Application\UseCase\SendSalesMessage;
use Domains\Sales\Application\UseCase\ScheduleSalesMeeting;
use Domains\Sales\Application\UseCase\AssignDealOwner;
use Domains\Sales\Application\UseCase\RecordIncomingMessage;
use Domains\Sales\Application\UseCase\NoActivityDetector;
use Domains\Sales\Application\UseCase\DetectMissedFollowups;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAttentionRepository;

$di->setShared('salesDomainModule', fn (): SalesDomainModule => new SalesDomainModule(
    $this->getShared('cosCrmGateway'),
    $this->getShared('cosCrmGateway'),
    $this->getShared('cosCrmGateway'),
    $this->getShared('salesRuleContextProvider'),
    $this->getShared('salesAgentContextBuilder'),
    $this->getShared('salesChangeDealStage'),
    $this->getShared('salesSendMessage'),
    $this->getShared('salesScheduleMeeting'),
    $this->getShared('salesAssignDealOwner'),
));

$di->setShared('salesCompleteCall', fn (): CompleteSalesCall => new CompleteSalesCall(
    $this->getShared('salesActivityRepository'),
    $this->getShared('eventBus'),
    $this->getShared('cosTransactionManager'),
));

// Sales application services are registered in the common composition root so Web,
// API, CLI, Telegram and workers all use the same use cases and transaction rules.
$di->setShared('salesClientCaseReadModel', fn (): MysqlClientCaseReadModel => new MysqlClientCaseReadModel(
    $this->getShared('databaseService')->connection(),
    $this->getShared('organizationContext')->id(),
));
$di->setShared('salesWorkspaceReadModel', fn (): MysqlSalesWorkspaceReadModel => new MysqlSalesWorkspaceReadModel(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesOutcomeRepository', fn (): MysqlSalesOutcomeRepository => new MysqlSalesOutcomeRepository(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesRecordActionOutcome', fn (): RecordActionOutcome => new RecordActionOutcome(
    $this->getShared('salesOutcomeRepository'), $this->getShared('eventBus'), $this->getShared('cosTransactionManager'),
));
$di->setShared('salesClientCaseCommands', fn (): MysqlClientCaseCommandRepository => new MysqlClientCaseCommandRepository(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesPipelineRepository', fn (): MysqlPipelineRepository => new MysqlPipelineRepository($this->getShared('databaseService')->connection()));
$di->setShared('salesDealRepository', fn (): MysqlDealRepository => new MysqlDealRepository($this->getShared('databaseService')->connection()));
$di->setShared('salesOperationRepository', fn (): MysqlSalesOperationRepository => new MysqlSalesOperationRepository($this->getShared('databaseService')->connection()));
$di->setShared('salesAttentionRepository', fn (): MysqlSalesAttentionRepository => new MysqlSalesAttentionRepository($this->getShared('databaseService')->connection()));
$di->setShared('salesNoActivityDetector', fn (): NoActivityDetector => new NoActivityDetector($this->getShared('salesAttentionRepository'),$this->getShared('eventBus'),$this->getShared('cosTransactionManager')));
$di->setShared('salesMissedFollowupDetector', fn (): DetectMissedFollowups => new DetectMissedFollowups($this->getShared('salesAttentionRepository'),$this->getShared('eventBus'),$this->getShared('cosTransactionManager')));
$di->setShared('salesSendMessage', fn (): SendSalesMessage => new SendSalesMessage(
    $this->getShared('cosCrmGateway'), $this->getShared('salesOperationRepository'), $this->getShared('eventBus'), $this->getShared('cosTransactionManager'),
));
$di->setShared('salesScheduleMeeting', fn (): ScheduleSalesMeeting => new ScheduleSalesMeeting(
    $this->getShared('salesOperationRepository'), $this->getShared('cosTransactionManager'),
));
$di->setShared('salesRecordIncomingMessage', fn (): RecordIncomingMessage => new RecordIncomingMessage(
    $this->getShared('salesOperationRepository'), $this->getShared('eventBus'), $this->getShared('cosTransactionManager'),
));
$di->setShared('salesChangeDealStage', fn (): ChangeDealStage => new ChangeDealStage(
    $this->getShared('salesDealRepository'), $this->getShared('salesPipelineRepository'), new StageTransitionPolicy(),
    $this->getShared('eventBus'), $this->getShared('cosTransactionManager'),
));
$di->setShared('salesAssignDealOwner', fn (): AssignDealOwner => new AssignDealOwner(
    $this->getShared('salesDealRepository'), $this->getShared('eventBus'), $this->getShared('cosTransactionManager'),
));
$di->setShared('salesCreateClientCase', fn (): CreateClientCase => new CreateClientCase(
    $this->getShared('salesClientCaseCommands'), $this->getShared('eventBus'),
    $this->getShared('cosTransactionManager'), $this->getShared('organizationContext')->id(),
));
$di->setShared('salesUpdateClientCase', fn (): UpdateClientCase => new UpdateClientCase(
    $this->getShared('salesClientCaseReadModel'), $this->getShared('salesClientCaseCommands'),
    $this->getShared('eventBus'), $this->getShared('cosTransactionManager'), $this->getShared('organizationContext')->id(),
    $this->getShared('salesPipelineRepository'), $this->getShared('salesChangeDealStage'),
    $this->getShared('salesAssignDealOwner'),
));
$di->setShared('salesQuickUpdateClientCase', fn (): QuickUpdateClientCase => new QuickUpdateClientCase(
    $this->getShared('salesClientCaseReadModel'), $this->getShared('salesClientCaseCommands'),
    $this->getShared('eventBus'), $this->getShared('cosTransactionManager'), $this->getShared('organizationContext')->id(),
    $this->getShared('salesPipelineRepository'), $this->getShared('salesChangeDealStage'),
    $this->getShared('salesAssignDealOwner'),
));
$di->setShared('salesAddClientCaseActivity', fn (): AddClientCaseActivity => new AddClientCaseActivity(
    $this->getShared('salesClientCaseReadModel'), $this->getShared('salesClientCaseCommands'),
    $this->getShared('salesCompleteCall'), $this->getShared('cosTransactionManager'), $this->getShared('organizationContext')->id(),
    $this->getShared('eventBus'),
));
$di->setShared('salesAttachInboundRequest', fn (): AttachInboundRequest => new AttachInboundRequest(
    $this->getShared('salesClientCaseReadModel'), $this->getShared('salesClientCaseCommands'),
    $this->getShared('eventBus'), $this->getShared('cosTransactionManager'), $this->getShared('organizationContext')->id(),
));
$di->setShared('salesUpdateInboundClientCaseRequest', fn (): UpdateInboundClientCaseRequest => new UpdateInboundClientCaseRequest(
    $this->getShared('salesClientCaseReadModel'), $this->getShared('salesClientCaseCommands'),
    $this->getShared('eventBus'), $this->getShared('cosTransactionManager'), $this->getShared('organizationContext')->id(),
    $this->getShared('salesPipelineRepository'), $this->getShared('salesChangeDealStage'),
));
$di->setShared('salesCreateClientCaseFromInboundRequest', fn (): CreateClientCaseFromInboundRequest => new CreateClientCaseFromInboundRequest(
    $this->getShared('salesClientCaseCommands'), $this->getShared('eventBus'),
    $this->getShared('cosTransactionManager'), $this->getShared('organizationContext')->id(),
));
$di->setShared('salesAddClientCasePropertyMatch', fn (): AddClientCasePropertyMatch => new AddClientCasePropertyMatch(
    $this->getShared('salesClientCaseReadModel'), $this->getShared('salesClientCaseCommands'),
    $this->getShared('cosTransactionManager'), $this->getShared('organizationContext')->id(),
));
$di->setShared('salesUpdateClientCasePropertyMatch', fn (): UpdateClientCasePropertyMatch => new UpdateClientCasePropertyMatch(
    $this->getShared('salesClientCaseReadModel'), $this->getShared('salesClientCaseCommands'),
    $this->getShared('cosTransactionManager'), $this->getShared('organizationContext')->id(),
));
$di->setShared('salesEnsureInboundClientCase', fn (): EnsureInboundClientCase => new EnsureInboundClientCase(
    $this->getShared('salesClientCaseCommands'), $this->getShared('eventBus'),
    $this->getShared('cosTransactionManager'), $this->getShared('organizationContext')->id(),
));
$di->setShared('salesRegisterInboundClientCaseRequest', fn (): RegisterInboundClientCaseRequest => new RegisterInboundClientCaseRequest(
    $this->getShared('salesClientCaseCommands'), $this->getShared('cosTransactionManager'),
    $this->getShared('organizationContext')->id(),
));
$di->setShared('salesResolveInboundProperty', fn (): ResolveInboundProperty => new ResolveInboundProperty(
    $this->getShared('salesClientCaseCommands'),
));
$di->setShared('salesInboundLeadRepository', fn (): MysqlInboundLeadRepository => new MysqlInboundLeadRepository(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesInboundCaseResolver', fn (): InboundCaseResolverAdapter => new InboundCaseResolverAdapter(
    $this->getShared('salesResolveInboundProperty'), $this->getShared('salesEnsureInboundClientCase'),
    $this->getShared('salesRegisterInboundClientCaseRequest'),
));
$di->setShared('salesReceivePublicLead', fn (): ReceivePublicLead => new ReceivePublicLead(
    $this->getShared('salesInboundLeadRepository'), $this->getShared('salesInboundCaseResolver'),
    $this->getShared('eventBus'), $this->getShared('cosTransactionManager'),
    $this->getShared('organizationContext')->id(),
));

$di->setShared('salesReceiveCrmWebhook', fn (): ReceiveCrmWebhook => new ReceiveCrmWebhook(
    $this->getShared('cosCrmWebhookSecrets'),
    $this->getShared('cosCrmInbox'),
    $this->getShared('cosJobQueue'),
    $this->getShared('cosTransactionManager'),
));
$di->setShared('salesProcessCrmInbox', fn (): ProcessCrmInbox => new ProcessCrmInbox(
    $this->getShared('cosCrmInbox'),
    $this->getShared('cosCrmInboundApplier'),
    $this->getShared('cosDomainRegistry'),
    $this->getShared('eventBus'),
    $this->getShared('cosTransactionManager'),
    $this->getShared('salesPipelineRepository'),
    $this->getShared('salesChangeDealStage'),
    $this->getShared('salesRecordIncomingMessage'),
));
$di->setShared('salesCrmInboxJobHandler', fn (): CrmInboxJobHandler => new CrmInboxJobHandler(
    $this->getShared('salesProcessCrmInbox'),
));
