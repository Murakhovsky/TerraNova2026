<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Kernel\Module\ActiveModuleResolver;
use Kernel\Tool\Contract\ToolPermissionCheckerInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolPermission;

final readonly class ResearchAgentToolPermissionChecker implements ToolPermissionCheckerInterface
{
    private const ALLOWED=[
        'research.searchhypotheses',
        'research.createhypothesis',
        'research.createexperimentdraft',
        'research.searchknowledge',
    ];

    public function __construct(private ActiveModuleResolver $modules){}

    public function allows(ToolPermission $permission,ToolDefinition $definition,ToolInvocation $invocation):bool
    {
        $name=$definition->name();
        if(!in_array($name,self::ALLOWED,true))return false;
        if($permission->name()!=='tool.'.$name.'.execute')return false;
        return $this->modules->isEnabled($invocation->organizationId()->value(),'capital_markets');
    }
}
