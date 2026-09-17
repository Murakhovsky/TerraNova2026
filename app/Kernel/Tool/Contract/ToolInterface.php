<?php
declare(strict_types=1);

namespace Kernel\Tool\Contract;

use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolResult;

interface ToolInterface
{
    public function definition(): ToolDefinition;

    public function invoke(ToolInvocation $invocation): ToolResult;
}
