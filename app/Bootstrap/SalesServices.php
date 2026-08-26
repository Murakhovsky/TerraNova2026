<?php
declare(strict_types=1);

use Domains\Sales\Bootstrap\SalesDomainModule;
use Domains\Sales\Application\UseCase\CompleteSalesCall;
use Domains\Sales\Application\UseCase\ProcessCrmInbox;
use Domains\Sales\Application\UseCase\ReceiveCrmWebhook;
use Domains\Sales\Automation\Job\CrmInboxJobHandler;

$di->setShared('salesDomainModule', fn (): SalesDomainModule => new SalesDomainModule(
    $this->getShared('cosCrmGateway'),
    $this->getShared('cosCrmGateway'),
    $this->getShared('cosCrmGateway'),
    $this->getShared('cosCrmGateway'),
    $this->getShared('salesRuleContextProvider'),
    $this->getShared('salesAgentContextBuilder'),
));

$di->setShared('salesCompleteCall', fn (): CompleteSalesCall => new CompleteSalesCall(
    $this->getShared('salesActivityRepository'),
    $this->getShared('eventBus'),
    $this->getShared('cosTransactionManager'),
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
));
$di->setShared('salesCrmInboxJobHandler', fn (): CrmInboxJobHandler => new CrmInboxJobHandler(
    $this->getShared('salesProcessCrmInbox'),
));
