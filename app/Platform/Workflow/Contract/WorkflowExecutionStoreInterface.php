<?php
declare(strict_types=1);

namespace Platform\Workflow\Contract;

use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowRunSnapshot;
use Platform\Workflow\Model\WorkflowRunTransition;
use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Model\WorkflowStepCheckpoint;
use Platform\Workflow\Model\WorkflowStepSnapshot;

/**
 * The sole technical Run/Step writer after the guarded migration. Implementations
 * MUST persist tenant-scoped compare-and-swap, claim fencing and checkpoints.
 */
interface WorkflowExecutionStoreInterface
{
    public function create(WorkflowRunDraft $draft): WorkflowRunSnapshot;
    public function get(WorkflowRunIdentity $identity): ?WorkflowRunSnapshot;
    /** @return list<WorkflowStepSnapshot> */
    public function steps(WorkflowRunIdentity $identity): array;
    public function compareAndTransition(WorkflowRunTransition $transition): WorkflowRunSnapshot;
    public function claim(WorkflowStepClaim $claim): WorkflowStepSnapshot;
    public function checkpoint(WorkflowStepCheckpoint $checkpoint): WorkflowStepSnapshot;
}
