<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use DomainException;
use Kernel\Agent\Contract\AgentRuntimeInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tool\Contract\ToolRuntimeInterface;
use Kernel\Workflow\Contract\SystemStepHandlerInterface;
use Kernel\Workflow\Contract\WorkflowStateManagerInterface;
use Kernel\Workflow\Model\Step\SystemStep;
use Kernel\Workflow\Model\WorkflowExecution;
use Kernel\Workflow\Model\WorkflowInstance;
use Kernel\Workflow\Service\PathConditionEvaluator;
use Kernel\Workflow\Service\WorkflowEngine;
use Platform\Orchestration\Goal\GoalWorkflowProjection;

/**
 * Audited canonical WorkflowEngine vertical slice. Only a single inert
 * checkpoint SystemStep with no transitions or config can run, never Agent/Tool/external System.
 * Completion does NOT imply that business Goal success criteria were met.
 */
final readonly class FederationReadOnlyWorkflowRunner
{
    public function __construct(
        private FederationWorkflowPreflight $preflight,
        private FederationGoalStore $goals,
        private GoalWorkflowProjection $projection,
        private WorkflowStateManagerInterface $states,
    ) {}

    /** @return array<string,mixed> */
    public function run(
        TenantContext $actor,
        string $runId,
        string $planId,
        WorkflowInstance $workflow,
        string $approvalActionId,
    ): array {
        $definition = $workflow->workflow->definition;
        $step = $definition->steps[0] ?? null;
        if (count($definition->steps) !== 1
            || !$step instanceof SystemStep
            || $step->operation !== 'federation.read_only.checkpoint'
            || $step->payload !== []
            || $definition->transitions !== []
            || $workflow->configuration !== []) {
            throw new DomainException('Read-only runner requires a single literal checkpoint SystemStep and no transitions/config.');
        }
        $verified = $this->preflight->inspect($actor, $planId, $workflow, $approvalActionId);
        $goal = $this->goals->specification($actor, $verified['goal_id']);
        if ($goal === null || $goal->version !== $verified['specification_version']) {
            throw new DomainException('Goal specification changed before approved execution.');
        }

        // Under plan row lock startApprovedRun re-verifies canonical human Approval.
        $this->goals->startApprovedRun($actor, $runId, $planId, $approvalActionId);
        $this->goals->transitionRun($actor, $runId, 'pending', 'running', 1);
        $this->goals->claimStep($actor, $runId, $step->id);

        // Defense in depth: even a future graph-validation regression cannot
        // accidentally call production LLM/Tool runtimes from this executor.
        $agents = new class implements AgentRuntimeInterface {
            public function execute(
                \Kernel\Agent\Model\AgentInstance $instance,
                \Kernel\Agent\Model\AgentContext $context,
            ): \Kernel\Agent\Model\AgentRun {
                throw new DomainException('Agent dispatch is disabled in read-only Federation.');
            }
        };
        $tools = new class implements ToolRuntimeInterface {
            public function execute(\Kernel\Tool\Model\ToolInvocation $invocation): \Kernel\Tool\Model\ToolExecution
            {
                throw new DomainException('Tool dispatch is disabled in read-only Federation.');
            }
        };
        $systems = new class implements SystemStepHandlerInterface {
            public function execute(SystemStep $step, array $payload, WorkflowExecution $execution): array
            {
                if ($step->operation !== 'federation.read_only.checkpoint' || $payload !== []) {
                    throw new DomainException('Only the inert Federation checkpoint operation is supported.');
                }
                return ['checkpoint' => true];
            }
        };
        $engine = new WorkflowEngine(
            $agents, $tools, new PathConditionEvaluator(), $systems, $this->states,
        );
        $execution = $engine->start($workflow, [
            'goal_id' => $goal->goalId,
            'federation_run_id' => $runId,
            'approved_plan_id' => $planId,
        ]);
        $projection = $this->projection->project($goal, $execution, [$step->id]);
        if ($projection['run_state'] !== 'completed'
            || count($projection['steps']) !== 1
            || $projection['steps'][0]['step_id'] !== $step->id
            || $projection['steps'][0]['state'] !== 'completed') {
            // A claimed run is intentionally never automatically restarted.
            throw new DomainException('Canonical Workflow failed to complete its single inert decision.');
        }
        $this->goals->completeReadOnlyWorkflowRun($actor, $runId, $step->id, $projection);
        return ['run_id' => $runId] + $projection;
    }
}
