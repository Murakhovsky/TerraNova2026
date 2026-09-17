<?php
declare(strict_types=1);

namespace Kernel\Tool\Contract;

use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolInvocation;

interface ToolInputValidatorInterface
{
    public function validate(ToolDefinition $definition, ToolInvocation $invocation): void;
}
