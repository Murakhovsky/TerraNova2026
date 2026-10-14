<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use DomainException;
use Platform\Workflow\Contract\WorkflowNonActionReceiptEvidenceInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

final readonly class FailClosedWorkflowNonActionReceiptEvidence implements WorkflowNonActionReceiptEvidenceInterface
{
    public function assertVerified(
        WorkflowRunIdentity $identity,
        string $stepId,
        string $reference,
        string $inputDigest,
        array $pinnedStep,
    ): void {
        throw new DomainException('Non-Action Workflow Step has no independent domain-owned receipt verifier.');
    }
}
