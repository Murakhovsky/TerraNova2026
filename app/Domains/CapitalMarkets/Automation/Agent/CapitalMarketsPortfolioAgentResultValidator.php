<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Automation\Agent;
use InvalidArgumentException;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentResult;
use Kernel\Agent\Contract\AgentResultValidatorInterface;
final class CapitalMarketsPortfolioAgentResultValidator implements AgentResultValidatorInterface
{
 private const TOOLS=[
  'portfolio.getstate','portfolio.getexposure','portfolio.getrisk','portfolio.simulateopportunityimpact',
  'portfolio.simulateallocation','portfolio.comparescenarios','allocation.propose','risk.runstress',
  'strategy.getscorecard','performance.compare'
 ];
 public function validate(AgentResult $result,AgentDefinition $agent):void
 {
  if($result->proposedActions!==[])throw new InvalidArgumentException('Portfolio Agent cannot emit executable business actions.');
  $portfolio=$result->evidence['portfolio']??null;
  if(!is_array($portfolio)||array_is_list($portfolio))throw new InvalidArgumentException('Portfolio Agent requires evidence.portfolio object.');
  $requests=$portfolio['tool_requests']??[];
  if(!is_array($requests)||count($requests)>6)throw new InvalidArgumentException('Portfolio Agent may request at most six tools per pass.');
  foreach($requests as $request){
   if(!is_array($request)||array_is_list($request))throw new InvalidArgumentException('Portfolio Agent tool request must be an object.');
   $name=strtolower(trim((string)($request['name']??'')));if(!in_array($name,self::TOOLS,true))throw new InvalidArgumentException('Portfolio Agent requested forbidden tool: '.$name);
   $inputJson=$request['input_json']??null;if(!is_string($inputJson)||trim($inputJson)==='')throw new InvalidArgumentException('Portfolio Agent tool request input_json must be a non-empty JSON object string.');
   try{$decoded=json_decode($inputJson,true,512,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new InvalidArgumentException('Portfolio Agent tool request input_json is malformed.',0,$e);}
   if(!is_array($decoded)||array_is_list($decoded))throw new InvalidArgumentException('Portfolio Agent tool request input_json must encode an object.');
  }
  $authorityText=strtoupper(implode(' ',[
   $result->decision,$result->reason,
   implode(' ',array_map('strval',(array)($portfolio['findings']??[]))),
   implode(' ',array_map('strval',(array)($portfolio['limitations']??[]))),
  ]));
  foreach(['APPROVE MY OWN','OVERRIDE HARD','DISABLE KILL','MOVE LIVE CAPITAL','MUTATE LEDGER','CHANGE RISK LIMIT'] as $forbidden){
   if(str_contains($authorityText,$forbidden))throw new InvalidArgumentException('Portfolio Agent recommendation exceeds authority.');
  }
 }
}
