<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Contract\AgentRuntimeInterface;
use Kernel\Agent\Model\Agent;
use Kernel\Agent\Model\AgentContext;
use Kernel\Agent\Model\AgentInstance;
use Kernel\Agent\Model\AgentOutput;
use Kernel\Agent\Model\AgentRun;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Tool\Contract\ToolRuntimeInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolExecution;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolPermission;
use Kernel\Tool\Model\ToolResult;
use Kernel\Workflow\Contract\SystemStepHandlerInterface;
use Kernel\Workflow\Contract\WorkflowStateManagerInterface;
use Kernel\Workflow\Model\Assignment;
use Kernel\Workflow\Model\AssignmentType;
use Kernel\Workflow\Model\Condition;
use Kernel\Workflow\Model\ConditionOperator;
use Kernel\Workflow\Model\Step\AgentStep;
use Kernel\Workflow\Model\Step\DecisionStep;
use Kernel\Workflow\Model\Step\HumanStep;
use Kernel\Workflow\Model\Step\SystemStep;
use Kernel\Workflow\Model\Step\ToolStep;
use Kernel\Workflow\Model\Step\WaitStep;
use Kernel\Workflow\Model\Transition;
use Kernel\Workflow\Model\Workflow;
use Kernel\Workflow\Model\WorkflowDefinition;
use Kernel\Workflow\Model\WorkflowExecution;
use Kernel\Workflow\Model\WorkflowInstance;
use Kernel\Workflow\Model\WorkflowStatus;
use Kernel\Workflow\Service\PathConditionEvaluator;
use Kernel\Workflow\Service\WorkflowEngine;
use LogicException;

function workflowExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$states = new class implements WorkflowStateManagerInterface {
    public function assertCanTransition(WorkflowStatus $from, WorkflowStatus $to): void
    {
        $allowed = [
            'created' => ['running', 'cancelled'],
            'running' => ['waiting', 'completed', 'failed', 'cancelled'],
            'waiting' => ['running', 'failed', 'cancelled'],
            'completed' => [], 'failed' => [], 'cancelled' => [],
        ];
        if (!in_array($to->value, $allowed[$from->value], true)) throw new LogicException('Invalid workflow transition.');
    }
};

$organization = OrganizationId::fromString('workflow-org');
$agentDefinition = new AgentDefinition('lead_agent', '1', 'system', 'p1', 's1', [], configurationManaged: false);
$agentInstance = new AgentInstance('agent-instance', $organization, new Agent('lead_agent', $agentDefinition));

$agents = new class implements AgentRuntimeInterface {
    public int $calls = 0;
    public function execute(AgentInstance $instance, AgentContext $context): AgentRun
    {
        ++$this->calls;
        $run = new AgentRun('agent-run-' . $this->calls, $instance, $context);
        $run->queue(); $run->start();
        $run->complete(new AgentOutput('qualified', ['score' => 91], 'test', 'test-model'));
        return $run;
    }
};

$tools = new class implements ToolRuntimeInterface {
    public int $calls = 0;
    public function execute(ToolInvocation $invocation): ToolExecution
    {
        ++$this->calls;
        $definition = new ToolDefinition($invocation->toolName(), 'test', ['type' => 'object'], [], ToolEffect::READ);
        $execution = new ToolExecution('tool-execution-' . $this->calls, $definition, $invocation, ToolPermission::execute($definition->name()));
        $execution->authorize(); $execution->startAttempt();
        $execution->complete(ToolResult::success(['lead_id' => $invocation->input()['lead_id'] ?? null]));
        return $execution;
    }
};

$systems = new class implements SystemStepHandlerInterface {
    public function execute(SystemStep $step, array $payload, WorkflowExecution $execution): array
    {
        return ['qualified' => true, 'lead_id' => $payload['lead_id'] ?? null];
    }
};

$steps = [
    new SystemStep('prepare', 'Prepare lead', 'prepare_lead', ['lead_id' => '$.input.lead_id']),
    new DecisionStep('route', 'Route lead'),
    new AgentStep('qualify', 'AI qualification', $agentInstance, ['lead_id' => '$.steps.prepare.lead_id']),
    new ToolStep('sync', 'Sync lead', 'crm.lead.sync', ['lead_id' => '$.steps.prepare.lead_id']),
    new WaitStep('wait_external', 'Wait for external confirmation', 'external_confirmation'),
    new HumanStep('manager_review', 'Manager review', new Assignment(AssignmentType::ROLE, 'sales_manager'), 'Review the lead.'),
];
$definition = new WorkflowDefinition(
    'lead_orchestration',
    '1.0',
    'Lead orchestration',
    'prepare',
    $steps,
    [
        new Transition('prepare', 'route'),
        new Transition('route', 'qualify', new Condition('steps.prepare.qualified', ConditionOperator::EQ, true), 'qualified'),
        new Transition('route', 'manager_review', null, 'fallback'),
        new Transition('qualify', 'sync'),
        new Transition('sync', 'wait_external'),
        new Transition('wait_external', 'manager_review'),
    ],
);
$instance = new WorkflowInstance('workflow-instance', $organization, new Workflow('lead_orchestration', $definition));
$engine = new WorkflowEngine($agents, $tools, new PathConditionEvaluator(), $systems, $states, 25);

$execution = $engine->start($instance, ['lead_id' => 42]);
workflowExpect($execution->status() === WorkflowStatus::WAITING, 'Workflow must pause on WaitStep.');
workflowExpect($execution->waitingReason() === 'signal:external_confirmation', 'WaitStep must expose its signal.');
workflowExpect($execution->currentStepId() === 'wait_external', 'WaitStep must remain current while waiting.');
workflowExpect($agents->calls === 1 && $tools->calls === 1, 'Agent and Tool runtimes must each execute exactly once.');
workflowExpect(($execution->context()['steps']['sync']['output']['lead_id'] ?? null) === 42, 'Tool output must be available to subsequent workflow context.');

$execution = $engine->resume($execution, ['confirmed' => true]);
workflowExpect($execution->status() === WorkflowStatus::WAITING, 'Workflow must pause on HumanStep.');
workflowExpect($execution->waitingReason() === 'human:manager_review', 'HumanStep must expose a human wait reason.');
workflowExpect($execution->currentStepId() === 'manager_review', 'HumanStep must remain current while waiting.');

$execution = $engine->resume($execution, ['approved' => true]);
workflowExpect($execution->status() === WorkflowStatus::COMPLETED, 'Workflow must complete after the terminal human step.');
workflowExpect(($execution->context()['steps']['manager_review']['approved'] ?? null) === true, 'Human result must be recorded in workflow context.');
workflowExpect(count($execution->stepExecutions()) === 6, 'Workflow must keep a trace for every executed step.');

echo "Kernel Workflow Engine contract passed.\n";
