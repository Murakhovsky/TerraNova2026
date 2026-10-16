<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

use InvalidArgumentException;
use Kernel\Workflow\Model\StepStatus;

/** No completion is trusted until an independent receipt verifier has attested it. */
final readonly class WorkflowStepCheckpoint
{
    public function __construct(
        public WorkflowRunCommand $command,
        public string $stepId,
        public string $claimToken,
        public StepStatus $nextState,
        public ?string $resultReference = null,
    ) {
        if (trim($stepId) === '' || trim($claimToken) === ''
            || !in_array($nextState, [
                StepStatus::WAITING, StepStatus::COMPLETED,
                StepStatus::FAILED, StepStatus::AMBIGUOUS,
            ], true)) {
            throw new InvalidArgumentException('Unsupported Workflow Step checkpoint.');
        }
    }
}
