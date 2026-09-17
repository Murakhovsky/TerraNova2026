<?php
declare(strict_types=1);

namespace Kernel\Tool\Contract;

use Kernel\Tool\Model\ToolExecution;
use Kernel\Tool\Model\ToolInvocation;

interface ToolRuntimeInterface
{
    public function execute(ToolInvocation $invocation): ToolExecution;
}
