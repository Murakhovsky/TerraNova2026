<?php
declare(strict_types=1);

namespace Kernel\Tool\Contract;

use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolPermission;

interface ToolPermissionCheckerInterface
{
    public function allows(ToolPermission $permission, ToolDefinition $definition, ToolInvocation $invocation): bool;
}
