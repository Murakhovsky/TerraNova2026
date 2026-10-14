<?php
declare(strict_types=1);

namespace Platform\Workflow\Contract;

use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowStepDecision;

interface WorkflowStepSchedulerInterface
{
    /**
     * Pure and deterministic, no state mutation or Action dispatch.
     *
     * @param list<\Platform\Workflow\Model\WorkflowStepSnapshot> $persistedSteps
     * @param list<string> $allowedSideEffects
     */
    public function select(
        WorkflowDefinitionSnapshot $definition,
        array $persistedSteps,
        array $allowedSideEffects = ['external'],
    ): WorkflowStepDecision;
}
