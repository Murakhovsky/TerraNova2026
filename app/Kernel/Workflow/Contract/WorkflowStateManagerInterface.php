<?php
declare(strict_types=1);

namespace Kernel\Workflow\Contract;

use Kernel\Workflow\Model\WorkflowStatus;

interface WorkflowStateManagerInterface
{
    public function assertCanTransition(WorkflowStatus $from, WorkflowStatus $to): void;
}
