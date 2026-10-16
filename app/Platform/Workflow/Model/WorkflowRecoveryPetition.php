<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

use InvalidArgumentException;

final readonly class WorkflowRecoveryPetition
{
    public function __construct(
        public WorkflowRunCommand $command,
        public string $requestId,
        public string $stepId,
        public int $expectedStepRevision,
        public WorkflowRecoveryResolution $resolution,
        public string $reason,
        public ?string $receiptReference = null,
    ) {
        if (!preg_match('/^[a-f0-9]{32}$/D', $requestId)
            || trim($stepId) === '' || $expectedStepRevision < 1
            || mb_strlen(trim($reason)) < 12 || mb_strlen($reason) > 2000
            || ($resolution === WorkflowRecoveryResolution::VERIFIED_COMPLETE
                && ($receiptReference === null || $receiptReference === ''))
            || ($resolution !== WorkflowRecoveryResolution::VERIFIED_COMPLETE
                && $receiptReference !== null)) {
            throw new InvalidArgumentException('Recovery requires unique request, step CAS, exact resolution and recorded rationale.');
        }
    }
}
