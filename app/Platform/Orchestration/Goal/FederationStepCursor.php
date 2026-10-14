<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowStepSnapshot;
use Platform\Workflow\Service\WorkflowStepScheduler;

/**
 * Backward-compatible approved-Plan admission adapter. Universal deterministic
 * DAG selection now lives in Platform Workflow. External Action authorization
 * and receipt reconciliation remain in their existing trusted boundaries.
 */
final readonly class FederationStepCursor
{
    /**
     * @param list<array<string,mixed>> $planSteps
     * @param list<array<string,mixed>> $persistedSteps
     * @return array{state:string,step_id:?string,completed:list<string>}
     */
    public function select(array $planSteps, array $persistedSteps, bool $allowReadOnly = false): array
    {
        $definition = new WorkflowDefinitionSnapshot('federation.approved-plan', 'legacy', $planSteps);
        $snapshots = array_map(
            static fn (array $row): WorkflowStepSnapshot => WorkflowStepSnapshot::fromPersistedRow($row),
            $persistedSteps,
        );
        return (new WorkflowStepScheduler())->select(
            $definition, $snapshots, $allowReadOnly ? ['external', 'none'] : ['external'],
        )->toLegacyArray();
    }
}
