<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Automation\Agent;
use Kernel\Agent\Contract\AgentResultValidatorInterface;
use Kernel\Agent\Model\AgentResult;
use RuntimeException;
final class CapitalMarketsPortfolioAgentResultValidator implements AgentResultValidatorInterface
{
 private const TOOLS=[
  'portfolio.getstate','portfolio.getexposure','portfolio.getrisk','portfolio.simulateopportunityimpact',
  'portfolio.simulateallocation','portfolio.comparescenarios','allocation.propose','risk.runstress',
  'strategy.getscorecard','performance.compare'
 ];
 public function validate(AgentResult $result):void
 {
  if($result->proposedActions!==[])throw new RuntimeException('Portfolio Agent cannot emit executable business actions.');
  $portfolio=$result->evidence['portfolio']??null;if(!is_array($portfolio))throw new RuntimeException('Portfolio Agent evidence missing.');
  $requests=$portfolio['tool_requests']??[];if(!is_array($requests)||count($requests)>6)throw new RuntimeException('Portfolio Agent may request at most six tools per pass.');
  foreach($requests as $request){
   if(!is_array($request))throw new RuntimeException('Portfolio Agent tool request malformed.');
   $name=strtolower(trim((string)($request['name']??'')));if(!in_array($name,self::TOOLS,true))throw new RuntimeException('Portfolio Agent requested forbidden tool: '.$name);
   $json=(string)($request['input_json']??'');json_decode($json,true,512,JSON_THROW_ON_ERROR);
  }
  $reason=strtolower($result->reason);
  foreach(['override hard','disable kill','move live capital','approve my own','mutate ledger'] as $forbidden)if(str_contains($reason,$forbidden))throw new RuntimeException('Portfolio Agent recommendation exceeds authority.');
 }
}
