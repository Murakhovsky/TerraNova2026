<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Workflow;

use LogicException;

final readonly class WorkflowTransitionGuard
{
    public function __construct(
        private EngineeringWorkflowDefinition $definition = new EngineeringWorkflowDefinition(),
    ) {
    }

    public function assert(WorkflowExecution $workflow, EngineeringWorkflowState $target): void
    {
        if ($workflow->currentState()->isTerminal()) {
            throw new LogicException('Terminal engineering workflow cannot transition.');
        }

        $this->definition->assertCanTransition(
            $workflow->currentState(),
            $target,
            $workflow->resumeState(),
        );
    }
}
