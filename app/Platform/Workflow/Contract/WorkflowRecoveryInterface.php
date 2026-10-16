<?php
declare(strict_types=1);

namespace Platform\Workflow\Contract;

use Platform\Workflow\Model\WorkflowRecoveryAssessment;
use Platform\Workflow\Model\WorkflowRunCommand;

interface WorkflowRecoveryInterface
{
    public function inspect(WorkflowRunCommand $command): WorkflowRecoveryAssessment;
    /** Never automatically replay uncertain external side effects. */
    public function reconcile(WorkflowRunCommand $command): WorkflowRecoveryAssessment;
}
