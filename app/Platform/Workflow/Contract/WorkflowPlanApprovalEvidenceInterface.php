<?php
declare(strict_types=1);

namespace Platform\Workflow\Contract;

use Platform\Workflow\Model\WorkflowRunIdentity;

/** Proof of an approved immutable business Plan, not mere SQL state. */
interface WorkflowPlanApprovalEvidenceInterface
{
    public function assertTrusted(
        WorkflowRunIdentity $identity,
        string $planId,
        string $planDigest,
        string $approvalReference,
    ): void;
}
