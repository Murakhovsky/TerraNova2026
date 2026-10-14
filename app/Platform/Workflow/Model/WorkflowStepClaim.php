<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

use InvalidArgumentException;

/** Worker claim must be fenced by the authoritative persisted Run revision. */
final readonly class WorkflowStepClaim
{
    public function __construct(
        public WorkflowRunCommand $command,
        public string $stepId,
        public string $workerId,
        public int $leaseSeconds,
    ) {
        if (trim($stepId) === '' || trim($workerId) === ''
            || $leaseSeconds < 1 || $leaseSeconds > 86400) {
            throw new InvalidArgumentException('Workflow Step claim needs bounded lease and a worker identity.');
        }
    }
}
