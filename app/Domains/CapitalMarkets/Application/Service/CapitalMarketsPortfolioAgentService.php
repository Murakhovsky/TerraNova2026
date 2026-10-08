<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Service;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsPortfolioAgent;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Service\AgentRuntime;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Tool\Contract\ToolRuntimeInterface;
use Kernel\Tool\Model\ToolExecutionStatus;
use Kernel\Tool\Model\ToolInvocation;
use RuntimeException;
final readonly class CapitalMarketsPortfolioAgentService
{
 public function __construct(private AgentRuntime $runtime,private DomainModuleRegistry $domains,private ActiveModuleResolver $modules,private ToolRuntimeInterface $tools){}
 public function run(string $organizationId,string $subjectType,string $subjectId,string $question,string $correlationId):array
 {
  if(!$this->modules->isEnabled($organizationId,'capital_markets'))throw new RuntimeException('Capital Markets module is disabled.');
  $agentName=CapitalMarketsPortfolioAgent::NAME;
  if($this->domains->ownerOfAgent($agentName)!=='capital_markets')throw new RuntimeException('Portfolio Agent ownership is invalid.');
  $agent=$this->domains->agent($agentName);
  $first=$this->runtime->run($agent,new AgentInvocation($organizationId,$subjectType,$subjectId,$question,$correlationId,[],$agentName));
  $requests=$first->result->evidence['portfolio']['tool_requests']??[];$results=[];
  foreach(array_slice(is_array($requests)?$requests:[],0,6) as $index=>$request){
   if(!is_array($request))continue;$name=strtolower(trim((string)($request['name']??'')));
   $input=json_decode((string)($request['input_json']??'{}'),true,512,JSON_THROW_ON_ERROR);
   if(!is_array($input)||array_is_list($input))throw new RuntimeException('Portfolio tool input_json must encode an object.');
   $input['agent_name']=$agentName;
   $execution=$this->tools->execute(new ToolInvocation(OrganizationId::fromString($organizationId),$name,$input,$correlationId.':tool:'.$index));
   if($execution->status()!==ToolExecutionStatus::COMPLETED||$execution->result()===null)throw new RuntimeException($execution->error()??('Portfolio tool failed: '.$name));
   $results[]=['name'=>$name,'output'=>$execution->result()->output(),'metadata'=>$execution->result()->metadata()];
  }
  if($results===[])return ['initial_run_id'=>$first->runId,'final_run_id'=>$first->runId,'tool_results'=>[],'decision'=>$first->result->decision,'reason'=>$first->result->reason,'confidence'=>$first->result->confidence,'evidence'=>$first->result->evidence];
  $final=$this->runtime->run($agent,new AgentInvocation($organizationId,$subjectType,$subjectId,$question."\n\nFinalize using supplied deterministic tool results. Do not request additional tools.",$correlationId.':final',$results,$agentName));
  $remaining=$final->result->evidence['portfolio']['tool_requests']??[];if(is_array($remaining)&&$remaining!==[])throw new RuntimeException('Portfolio Agent requested tools after final pass.');
  return ['initial_run_id'=>$first->runId,'final_run_id'=>$final->runId,'tool_results'=>$results,'decision'=>$final->result->decision,'reason'=>$final->result->reason,'confidence'=>$final->result->confidence,'evidence'=>$final->result->evidence];
 }
}
