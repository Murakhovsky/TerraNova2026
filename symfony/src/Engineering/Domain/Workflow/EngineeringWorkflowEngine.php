<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Workflow;

final readonly class EngineeringWorkflowEngine
{
    public function __construct(
        private WorkflowTransitionGuard $guard = new WorkflowTransitionGuard(),
    ) {
    }

    public function canTransition(WorkflowExecution $workflow, EngineeringWorkflowState $target): bool
    {
        try {
            $this->guard->assert($workflow, $target);
            return true;
        } catch (\LogicException) {
            return false;
        }
    }

    public function transition(
        WorkflowExecution $workflow,
        EngineeringWorkflowState $target,
        WorkflowTransitionContext $context,
    ): WorkflowTransition {
        $this->guard->assert($workflow, $target);
        $from = $workflow->currentState();

        $transition = new WorkflowTransition(
            EngineeringId::generate(),
            $workflow->id(),
            $workflow->featureId(),
            $from,
            $target,
            $context,
            new \DateTimeImmutable(),
        );

        $workflow->moveTo($target);

        return $transition;
    }
}
