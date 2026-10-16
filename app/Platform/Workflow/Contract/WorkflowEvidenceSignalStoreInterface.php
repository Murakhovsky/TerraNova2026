<?php
declare(strict_types=1);

namespace Platform\Workflow\Contract;

use Platform\Workflow\Model\WorkflowEvidenceSignal;
use Platform\Workflow\Model\WorkflowStepSnapshot;

/** The one canonical Run/Step writer owns verified Wait -> completed transitions. */
interface WorkflowEvidenceSignalStoreInterface
{
    public function completeWaitingEvidence(WorkflowEvidenceSignal $signal): WorkflowStepSnapshot;
}
