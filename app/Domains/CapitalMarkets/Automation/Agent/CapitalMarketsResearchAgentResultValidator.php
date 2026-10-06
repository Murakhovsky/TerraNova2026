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
            $inputJson=$request['input_json']??null;
            if(!is_string($inputJson)||trim($inputJson)===''){
                throw new InvalidArgumentException('Research tool request input_json must be a non-empty JSON object string.');
            }
            try{$decoded=json_decode($inputJson,true,512,JSON_THROW_ON_ERROR);}
            catch(\JsonException $error){
                throw new InvalidArgumentException('Research tool request input_json is malformed.',0,$error);
            }
            if(!is_array($decoded)||array_is_list($decoded)){
                throw new InvalidArgumentException('Research tool request input_json must encode an object.');
            }
        }

        $authorityText=strtoupper(implode(' ',[
            $result->decision,
            $result->reason,
            implode(' ',array_map('strval',(array)($research['findings']??[]))),
            implode(' ',array_map('strval',(array)($research['limitations']??[]))),
        ]));
        foreach(['ENABLE LIVE','ACTIVATE LIVE','AUTO_EXECUTE','OVERRIDE_RISK','CHANGE_RISK_LIMIT'] as $forbidden){
            if(str_contains($authorityText,$forbidden)){
                throw new InvalidArgumentException('Research Agent recommendation exceeds authority.');
            }
        }
    }
}
