<?php
declare(strict_types=1);

namespace Platform\Workflow\Contract;

use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Model\WorkflowStepSnapshot;

/** Re-check canonical Action/Policy/Approval at the claim boundary. */
interface WorkflowStepClaimAdmissionInterface
{
    public function assertAuthorized(WorkflowStepClaim $claim, WorkflowStepSnapshot $step): void;
}
