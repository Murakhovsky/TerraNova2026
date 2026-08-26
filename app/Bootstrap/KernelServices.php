<?php
declare(strict_types=1);

use Kernel\Action\Service\ActionExecutor;
use Kernel\Action\Service\ActionService;
use Kernel\Agent\Service\AgentRuntime;
use Kernel\Agent\Service\RoutedAgentContextBuilder;
use Kernel\Agent\Service\StructuredDecisionValidator;
use Kernel\Approval\Service\ApprovalService;
use Kernel\Event\EventBus;
use Kernel\Event\Service\EventDispatcher;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Policy\Service\ActionPolicyService;
use Kernel\Policy\Service\PolicyEngine;
use Kernel\Queue\Handler\ActionExecutionJobHandler;
use Kernel\Queue\Handler\AgentRunJobHandler;
use Kernel\Queue\Service\QueueWorker;
use Kernel\Rule\Service\ConditionEvaluator;
use Kernel\Rule\Service\DeterministicProcessEngine;
use Kernel\Rule\Service\QueuedActionProposalSink;
use Kernel\Rule\Service\RoutedRuleContextProvider;
use Kernel\Rule\Service\RuleEngine;
use Kernel\Rule\Service\RuleEngineEventHandler;

$di->setShared('cosDomainModules', fn (): array => [$this->getShared('salesDomainModule')]);
$di->setShared('cosDomainRegistry', fn (): DomainModuleRegistry => new DomainModuleRegistry($this->getShared('cosDomainModules')));

$di->setShared('cosConditionEvaluator', fn (): ConditionEvaluator => new ConditionEvaluator());
$di->setShared('cosRuleEngine', fn (): RuleEngine => new RuleEngine($this->getShared('cosConditionEvaluator')));
$di->setShared('cosDeterministicProcessEngine', fn (): DeterministicProcessEngine => new DeterministicProcessEngine($this->getShared('cosConditionEvaluator')));
$di->setShared('cosPolicyEngine', fn (): PolicyEngine => new PolicyEngine($this->getShared('cosConditionEvaluator')));
$di->setShared('cosEventDispatcher', fn (): EventDispatcher => new EventDispatcher());

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
));

$di->setShared('eventBus', function (): EventBus {
    $bus = new EventBus($this->getShared('eventStore'), $this->getShared('cosTransactionManager'));
    $bus->subscribe('*', $this->getShared('cosRuleEngineEventHandler'));
    return $bus;
});

$di->setShared('cosActionExecutor', fn (): ActionExecutor => new ActionExecutor($this->getShared('cosDomainRegistry')->actionHandlers()));
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
));
$di->setShared('cosAgentRunJobHandler', fn (): AgentRunJobHandler => new AgentRunJobHandler(
    $this->getShared('cosAgentRuntime'),
    $this->getShared('cosDomainRegistry'),
    $this->getShared('cosActionPolicyService'),
    $this->getShared('cosJobQueue'),
));
$di->setShared('cosActionExecutionJobHandler', fn (): ActionExecutionJobHandler => new ActionExecutionJobHandler($this->getShared('cosActionService')));
$di->setShared('cosQueueWorker', fn (): QueueWorker => new QueueWorker($this->getShared('cosJobQueue'), [
    $this->getShared('cosAgentRunJobHandler'),
    $this->getShared('cosActionExecutionJobHandler'),
]));
