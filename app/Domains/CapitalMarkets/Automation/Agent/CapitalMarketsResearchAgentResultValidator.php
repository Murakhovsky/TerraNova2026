<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Automation\Agent;

use InvalidArgumentException;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentResult;
use Kernel\Agent\Contract\AgentResultValidatorInterface;

final class CapitalMarketsResearchAgentResultValidator implements AgentResultValidatorInterface
{
    private const TOOLS=[
        'research.searchhypotheses',
        'research.createhypothesis',
        'research.createexperimentdraft',
        'research.searchknowledge',
    ];

    public function validate(AgentResult $result,AgentDefinition $agent):void
    {
        if($result->proposedActions!==[]){
            throw new InvalidArgumentException('Research Agent cannot emit business action proposals.');
        }

        $research=$result->evidence['research']??null;
        if(!is_array($research)||array_is_list($research)){
            throw new InvalidArgumentException('Research Agent requires evidence.research object.');
        }

        $requests=$research['tool_requests']??[];
        if(!is_array($requests)||count($requests)>4){
            throw new InvalidArgumentException('Research Agent may request at most four tools per pass.');
        }
        foreach($requests as $request){
            if(!is_array($request)||array_is_list($request)){
                throw new InvalidArgumentException('Research tool request must be an object.');
            }
            $name=strtolower(trim((string)($request['name']??'')));
            if(!in_array($name,self::TOOLS,true)){
                throw new InvalidArgumentException('Research Agent requested forbidden tool: '.$name);
            }
            if(!is_array($request['input']??null)||array_is_list($request['input'])){
                throw new InvalidArgumentException('Research tool request input must be an object.');
            }
        }

        $recommendation=strtoupper((string)($research['recommendation']??''));
        foreach(['LIVE','AUTO_EXECUTE','OVERRIDE_RISK','CHANGE_RISK_LIMIT'] as $forbidden){
            if(str_contains($recommendation,$forbidden)){
                throw new InvalidArgumentException('Research Agent recommendation exceeds authority.');
            }
        }
    }
}
