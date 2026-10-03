<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Workflow;

use DateTimeImmutable;

final readonly class WorkflowTransition
{
    public function __construct(
        public string $id,
        public string $workflowExecutionId,
        public string $featureId,
        public EngineeringWorkflowState $from,
        public EngineeringWorkflowState $to,
        public WorkflowTransitionContext $context,
        public DateTimeImmutable $createdAt,
    ) {
        EngineeringId::assert($id);
        EngineeringId::assert($workflowExecutionId);
        EngineeringId::assert($featureId);
    }
}
