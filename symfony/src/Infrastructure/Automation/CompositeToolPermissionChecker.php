<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Kernel\Tool\Contract\ToolPermissionCheckerInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolPermission;

final readonly class CompositeToolPermissionChecker implements ToolPermissionCheckerInterface
{
    /** @param iterable<ToolPermissionCheckerInterface> $checkers */
    public function __construct(private iterable $checkers){}

    public function allows(ToolPermission $permission,ToolDefinition $definition,ToolInvocation $invocation):bool
    {
        foreach($this->checkers as $checker){
            if($checker->allows($permission,$definition,$invocation))return true;
        }
        return false;
    }
}
