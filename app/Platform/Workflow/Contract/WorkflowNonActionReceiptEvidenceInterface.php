<?php
declare(strict_types=1);

namespace Platform\Workflow\Contract;

use Platform\Workflow\Model\WorkflowRunIdentity;

/**
 * Domain-owned independent persisted proof for a completed non-Action Step.
 * A Workflow Step flag, checkpoint string or local hash alone is NOT evidence.
 */
interface WorkflowNonActionReceiptEvidenceInterface
{
    /** @param array<string,mixed> $pinnedStep */
    public function assertVerified(
        WorkflowRunIdentity $identity,
        string $stepId,
        string $reference,
        string $inputDigest,
        array $pinnedStep,
    ): void;
}
