<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use DomainException;
use Platform\Workflow\Contract\WorkflowPlanApprovalEvidenceInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/** Federation/Engineering Plan gates require explicit adapters before cutover. */
final readonly class FailClosedWorkflowPlanApprovalEvidence implements WorkflowPlanApprovalEvidenceInterface
{
    public function assertTrusted(
        WorkflowRunIdentity $identity,
        string $planId,
        string $planDigest,
        string $approvalReference,
    ): void {
        throw new DomainException('Canonical Workflow approved Plan evidence is not connected.');
    }
}
