<?php
declare(strict_types=1);

namespace Kernel\Workflow\Contract;

use Kernel\Workflow\Model\WorkflowExecution;
use Kernel\Workflow\Model\WorkflowInstance;

interface WorkflowEngineInterface
{
    /** @param array<string,mixed> $input */
    public function start(WorkflowInstance $instance, array $input = []): WorkflowExecution;

    /** @param array<string,mixed> $payload */
    public function resume(WorkflowExecution $execution, array $payload = []): WorkflowExecution;

    public function cancel(WorkflowExecution $execution): void;
}
