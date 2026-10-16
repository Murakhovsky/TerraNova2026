<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use DomainException;
use Platform\Workflow\Contract\WorkflowStepClaimAdmissionInterface;
use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Model\WorkflowStepSnapshot;

/** No production external claims before canonical Action binding is implemented. */
final readonly class FailClosedWorkflowStepClaimAdmission implements WorkflowStepClaimAdmissionInterface
{
    public function assertAuthorized(WorkflowStepClaim $claim, WorkflowStepSnapshot $step): void
    {
        throw new DomainException('Durable Workflow external Action admission is not enabled.');
    }
}
