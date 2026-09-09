<?php
declare(strict_types=1);

use Kernel\Action\Service\ActionExecutor;
use Kernel\Action\Service\ActionService;
use Kernel\Agent\Service\AgentRuntime;
use Kernel\Agent\Service\RoutedAgentContextBuilder;
use Kernel\Agent\Service\StructuredDecisionValidator;
use Kernel\Agent\Service\SensitiveContextRedactor;
use Kernel\Approval\Service\ApprovalService;
use Kernel\Configuration\Service\ConfigurationProvisioner;
use Kernel\Configuration\Service\ConfigurationValidator;
use Kernel\Event\EventBus;
use Kernel\Event\Service\DurableEventDispatcher;
use Kernel\Event\Service\OutboxPublisher;
use Kernel\Event\Service\OutboxReplayService;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Operations\Service\WorkerSupervisor;
use Kernel\Policy\Service\ActionPolicyService;
use Kernel\Policy\Service\PolicyEngine;
use Kernel\Queue\Handler\ActionExecutionJobHandler;
use Kernel\Queue\Handler\AgentRunJobHandler;
use Kernel\Queue\Service\QueueWorker;
use Kernel\Rule\Service\ConditionEvaluator;
use Kernel\Rule\Service\DeterministicProcessEngine;
use Kernel\Rule\Service\QueuedActionProposalSink;
use Kernel\Rule\Service\RoutedRuleContextProvider;
use Kernel\Rule\Service\RuleEngineEventHandler;

$di->setShared('cosDomainRegistry', fn (): DomainModuleRegistry => new DomainModuleRegistry($this->getShared('cosInstalledDomainModules')));
$di->setShared('cosConfigurationValidator', fn (): ConfigurationValidator => new ConfigurationValidator($this->getShared('cosDomainRegistry')));
$di->setShared('cosConfigurationProvisioner', fn (): ConfigurationProvisioner => new ConfigurationProvisioner(
    $this->getShared('cosDomainRegistry'),
    $this->getShared('cosConfigurationValidator'),
    $this->getShared('cosConfigurationStore'),
));

$di->setShared('cosConditionEvaluator', fn (): ConditionEvaluator => new ConditionEvaluator());
$di->setShared('cosDeterministicProcessEngine', fn (): DeterministicProcessEngine => new DeterministicProcessEngine($this->getShared('cosConditionEvaluator')));
$di->setShared('cosPolicyEngine', fn (): PolicyEngine => new PolicyEngine($this->getShared('cosConditionEvaluator')));

$di->setShared('cosRuleContextProvider', fn (): RoutedRuleContextProvider => new RoutedRuleContextProvider($this->getShared('cosDomainRegistry')));
$di->setShared('cosActionProposalSink', fn (): QueuedActionProposalSink => new QueuedActionProposalSink(
    $this->getShared('cosActionPolicyService'),
    $this->getShared('cosJobQueue'),
));
$di->setShared('cosRuleEngineEventHandler', fn (): RuleEngineEventHandler => new RuleEngineEventHandler(
    $this->getShared('cosRuleRepository'),
    $this->getShared('cosRuleContextProvider'),
    $this->getShared('cosRuleEvaluationRepository'),
    $this->getShared('cosActionProposalSink'),
    $this->getShared('cosDeterministicProcessEngine'),
    $this->getShared('cosDomainRegistry'),
    $this->getShared('cosActiveModuleResolver'),
));

$di->setShared('eventBus', fn (): EventBus => new EventBus(
    $this->getShared('eventStore'),
    $this->getShared('cosTransactionManager'),
));
$di->setShared('cosDurableEventDispatcher', fn (): DurableEventDispatcher => new DurableEventDispatcher(
    $this->getShared('cosEventConsumptions'),
    ['kernel.rule-engine.v1' => $this->getShared('cosRuleEngineEventHandler')],
));
$di->setShared('cosOutboxPublisher', fn (): OutboxPublisher => new OutboxPublisher(
    $this->getShared('cosEventOutbox'),
    $this->getShared('eventStore'),
    $this->getShared('cosDurableEventDispatcher'),
    $this->getShared('cosMetrics'),
    $this->getShared('cosLogger'),
));
$di->setShared('cosOutboxReplay', fn (): OutboxReplayService => new OutboxReplayService(
    $this->getShared('cosEventOutbox'),
    $this->getShared('cosEventConsumptions'),
    $this->getShared('cosTransactionManager'),
));

$di->setShared('cosActionExecutor', fn (): ActionExecutor => new ActionExecutor(
    $this->getShared('cosDomainRegistry')->actionHandlers(),
    $this->getShared('cosDomainRegistry'),
    $this->getShared('cosActiveModuleResolver'),
));
$di->setShared('cosActionService', fn (): ActionService => new ActionService(
    $this->getShared('cosActionRepository'),
    $this->getShared('cosActionExecutor'),
    $this->getShared('eventStore'),
    $this->getShared('cosAuditRepository'),
    $this->getShared('cosTransactionManager'),
));
$di->setShared('cosActionPolicyService', fn (): ActionPolicyService => new ActionPolicyService(
    $this->getShared('cosActionService'),
    $this->getShared('cosPolicyRepository'),
    $this->getShared('cosPolicyEvaluationRepository'),
    $this->getShared('cosApprovalRepository'),
    $this->getShared('cosPolicyEngine'),
    $this->getShared('cosTransactionManager'),
    $this->getShared('cosAuditRepository'),
    $this->getShared('cosDomainRegistry'),
    $this->getShared('cosActiveModuleResolver'),
));
$di->setShared('cosApprovalService', fn (): ApprovalService => new ApprovalService(
    $this->getShared('cosApprovalRepository'),
    $this->getShared('cosActionService'),
    $this->getShared('cosTransactionManager'),
    $this->getShared('cosAuditRepository'),
    $this->getShared('cosJobQueue'),
));

$di->setShared('cosAgentContextBuilder', fn (): RoutedAgentContextBuilder => new RoutedAgentContextBuilder($this->getShared('cosDomainRegistry')));
$di->setShared('cosAgentRuntime', fn (): AgentRuntime => new AgentRuntime(
    $this->getShared('cosAgentContextBuilder'),
    $this->getShared('cosLlmClient'),
    new StructuredDecisionValidator(),
    $this->getShared('cosAgentRunRepository'),
    $this->getShared('cosDecisionRepository'),
    new SensitiveContextRedactor(),
));
$di->setShared('cosAgentRunJobHandler', fn (): AgentRunJobHandler => new AgentRunJobHandler(
    $this->getShared('cosAgentRuntime'),
    $this->getShared('cosDomainRegistry'),
    $this->getShared('cosActionPolicyService'),
    $this->getShared('cosJobQueue'),
    $this->getShared('cosActiveModuleResolver'),
));
$di->setShared('cosActionExecutionJobHandler', fn (): ActionExecutionJobHandler => new ActionExecutionJobHandler($this->getShared('cosActionService')));
$di->setShared('cosQueueWorker', fn (): QueueWorker => new QueueWorker($this->getShared('cosJobQueue'), [
    $this->getShared('cosAgentRunJobHandler'),
    $this->getShared('cosActionExecutionJobHandler'),
    ...$this->getShared('cosModuleJobHandlers'),
], $this->getShared('cosMetrics'), $this->getShared('cosLogger')));
$di->setShared('cosWorkerSupervisor', fn (): WorkerSupervisor => new WorkerSupervisor(
    $this->getShared('cosOutboxPublisher'),
    $this->getShared('cosQueueWorker'),
    $this->getShared('cosMetrics'),
    $this->getShared('cosLogger'),
));
