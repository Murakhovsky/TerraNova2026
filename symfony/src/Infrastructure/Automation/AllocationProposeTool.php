<?php
declare(strict_types=1);
namespace App\Infrastructure\Automation;
use Domains\CapitalMarkets\Application\Service\CapitalRiskService;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\{ToolDefinition,ToolEffect,ToolInvocation,ToolResult};
final readonly class AllocationProposeTool implements ToolInterface {
 public function __construct(private CapitalRiskService $service){}
 public function definition():ToolDefinition{return new ToolDefinition('allocation.propose','Create an auditable allocation proposal only; approval remains external authority.',['type'=>'object','required'=>['agent_name','opportunities'],'properties'=>['agent_name'=>['type'=>'string'],'opportunities'=>['type'=>'array'],'mode'=>['type'=>'string'],'risk_state'=>['type'=>'string'],'hard_caps'=>['type'=>'object']]],['type'=>'object'],ToolEffect::WRITE);}
 public function invoke(ToolInvocation $i):ToolResult{$p=$i->input();$p['proposal_actor_type']='AGENT';$p['proposal_actor_id']='capital_markets_portfolio';return ToolResult::success($this->service->recalculateAllocation($i->organizationId()->value(),$p));}
}
