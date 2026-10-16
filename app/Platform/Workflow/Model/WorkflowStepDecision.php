<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

use DomainException;

final readonly class WorkflowStepDecision
{
    /** @param list<string> $completed */
    public function __construct(
        public WorkflowStepSelectionState $state,
        public ?string $stepId,
        public array $completed,
    ) {
        if (($state === WorkflowStepSelectionState::COMPLETE) !== ($stepId === null)) {
            throw new DomainException('Workflow selection must identify a step unless complete.');
        }
    }

    /** @return array{state:string,step_id:?string,completed:list<string>} */
    public function toLegacyArray(): array
    {
        return ['state' => $this->state->value, 'step_id' => $this->stepId,
            'completed' => $this->completed];
    }
}
