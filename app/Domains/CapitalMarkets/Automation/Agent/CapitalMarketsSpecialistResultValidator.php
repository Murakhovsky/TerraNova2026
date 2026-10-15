<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Automation\Agent;

use InvalidArgumentException;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentResult;
use Kernel\Agent\Contract\AgentResultValidatorInterface;

final class CapitalMarketsSpecialistResultValidator implements AgentResultValidatorInterface
{
    public function validate(AgentResult $result,AgentDefinition $agent):void
    {
        if($result->proposedActions!==[]){
            throw new InvalidArgumentException('Capital Markets specialists cannot emit business actions.');
        }
        if(!in_array($result->decision,['BLOCKED','REVIEW','NO_ACTION','PROPOSE'],true)){
            throw new InvalidArgumentException('Capital Markets specialist decision is invalid.');
        }
        if(!str_starts_with($agent->name,'capital_markets_')){
            throw new InvalidArgumentException('Invalid specialist identity.');
        }
        $stage=match($agent->name){
            CapitalMarketsMarketIntelligenceAgent::NAME=>'observe',
            CapitalMarketsOpportunityAgent::NAME=>'detect',
            CapitalMarketsRiskAgent::NAME=>'assess',
            CapitalMarketsExecutionAgent::NAME=>'execute',
            CapitalMarketsOrchestratorAgent::NAME=>'measure',
            default=>throw new InvalidArgumentException('Unknown specialist agent identity.'),
        };
        $details=$result->evidence[$stage]??null;
        if(!is_array($details)||array_is_list($details)||count($result->evidence)!==1){
            throw new InvalidArgumentException('Specialist requires exactly one typed evidence section.');
        }
        foreach(['findings','limitations','evidence_refs'] as $field){
            $rows=$details[$field]??null;
            if(!is_array($rows)||!array_is_list($rows)||count($rows)>20){
                throw new InvalidArgumentException('Specialist evidence entries must be bounded lists.');
            }
            foreach($rows as $row){
                if(!is_string($row)||strlen($row)>1024)throw new InvalidArgumentException('Invalid specialist evidence item.');
            }
        }
        if(count($details)!==3){
            throw new InvalidArgumentException('Specialist evidence contains undeclared fields.');
        }
    }
}
