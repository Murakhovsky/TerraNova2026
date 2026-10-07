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
        'research.gethypothesis',
        'research.searchexperiments',
        'research.getresult',
        'research.compareresults',
        'research.recordobservation',
        'dataset.search',
        'dataset.describe',
    ];

    public function __construct(private ActiveModuleResolver $modules){}

    public function allows(ToolPermission $permission,ToolDefinition $definition,ToolInvocation $invocation):bool
    {
        $name=$definition->name();
        if(!in_array($name,self::ALLOWED,true))return false;
        if($permission->name()!=='tool.'.$name.'.execute')return false;
        $input=$invocation->input();
        if(trim((string)($input['agent_name']??''))!=='capital_markets_research')return false;
        return $this->modules->isEnabled($invocation->organizationId()->value(),'capital_markets');
    }
}
