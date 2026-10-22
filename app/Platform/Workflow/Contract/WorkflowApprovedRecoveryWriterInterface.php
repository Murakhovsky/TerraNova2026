<?php
declare(strict_types=1);

namespace Platform\Workflow\Contract;

use Platform\Workflow\Model\WorkflowRunCommand;

/**
 * Exclusive technical Run/Step writer for an independently verified,
 * two-manager recovery decision. Caller owns the encompassing DB transaction.
 */
interface WorkflowApprovedRecoveryWriterInterface
{
    public function applyApprovedRecovery(
        WorkflowRunCommand $command,
        string $requestId,
        string $stepId,
        string $nextStepState,
        string $nextRunState,
        ?string $receiptReference
    ):void;
}
