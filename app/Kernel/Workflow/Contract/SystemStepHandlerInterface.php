<?php
declare(strict_types=1);

namespace Kernel\Workflow\Contract;

use Kernel\Workflow\Model\Step\SystemStep;
use Kernel\Workflow\Model\WorkflowExecution;

interface SystemStepHandlerInterface
{
    /** @return array<string,mixed> */
    public function execute(SystemStep $step, array $payload, WorkflowExecution $execution): array;
}
