<?php
declare(strict_types=1);

namespace Platform\Workflow\Contract;

use Kernel\Tenant\Model\TenantContext;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunSnapshot;

/**
 * Declarative execution control, never a second Action/Agent dispatcher.
 * No concrete implementation should be wired before durable store parity.
 */
interface WorkflowExecutionCoordinatorInterface
{
    public function start(WorkflowRunDraft $draft, TenantContext $actor): WorkflowRunSnapshot;
    public function advance(WorkflowRunCommand $command): WorkflowRunSnapshot;
    public function resume(WorkflowRunCommand $command): WorkflowRunSnapshot;
    public function cancel(WorkflowRunCommand $command): WorkflowRunSnapshot;
}
