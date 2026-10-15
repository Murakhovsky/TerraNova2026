<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use DomainException;
use Platform\Workflow\Contract\WorkflowStepClaimAdmissionInterface;
use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Model\WorkflowStepSnapshot;

/** One Workflow Store admission point; no second executor or writer. */
final readonly class WorkflowClaimAdmissionRouter implements WorkflowStepClaimAdmissionInterface
{
    public function __construct(
        private DoctrineWorkflowActionClaimAdmission $actions,
        private FederationNonActionClaimAdmission $nonActions,
    ) {}

    public function assertAuthorized(WorkflowStepClaim $claim, WorkflowStepSnapshot $step): void
    {
        match($step->sideEffectLevel) {
            'external'=>$this->actions->assertAuthorized($claim,$step),
            'none'=>$this->nonActions->assertAuthorized($claim,$step),
            default=>throw new DomainException('Unsupported Workflow Step side effect.'),
        };
    }
}
