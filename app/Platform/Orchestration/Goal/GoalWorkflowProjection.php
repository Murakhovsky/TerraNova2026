<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;
use Kernel\Workflow\Model\WorkflowExecution;
use Kernel\Workflow\Model\WorkflowStatus;

/**
 * Canonical Workflow -> Federation projection. This NEVER evaluates the Goal outcome
 * and never retries workflow steps. A run can complete while the Goal remains unmet.
 */
final readonly class GoalWorkflowProjection
{
    /**
     * @param list<string> $expectedStepIds
     * @return array{workflow_id:string,run_state:string,waiting_reason:?string,steps:list<array{step_id:string,state:string,receipt_reference:?string}>}
     */
    public function project(
        GoalSpecification $goal,
        WorkflowExecution $execution,
        array $expectedStepIds,
    ): array {
        if ($execution->instance->organizationId->value() !== $goal->organizationId) {
            throw new DomainException('Workflow result belongs to a different Goal tenant.');
        }
        if ($expectedStepIds === [] || count($expectedStepIds) !== count(array_unique($expectedStepIds))) {
            throw new DomainException('Federation projection requires a nonempty, unique step snapshot.');
        }
        $allowed = array_fill_keys($expectedStepIds, true);
        $steps = [];
        foreach ($execution->stepExecutions() as $step) {
            if (!isset($allowed[$step->stepId])) {
                throw new DomainException('Workflow emitted a step missing from immutable Goal plan.');
            }
            $state = $step->status()->value;
            $steps[] = [
                'step_id' => $step->stepId,
                'state' => $state,
                'receipt_reference' => null, // Workflow output is not a canonical Action receipt.
            ];
        }
        $runState = match ($execution->status()) {
            WorkflowStatus::CREATED => 'pending',
            WorkflowStatus::RUNNING => 'running',
            WorkflowStatus::WAITING => 'waiting',
            WorkflowStatus::COMPLETED => 'completed',
            WorkflowStatus::FAILED => 'failed',
            WorkflowStatus::CANCELLED => 'cancelled',
        };
        return [
            'workflow_id' => $execution->id,
            'run_state' => $runState,
            'waiting_reason' => $execution->waitingReason(),
            'steps' => $steps,
        ];
    }
}
