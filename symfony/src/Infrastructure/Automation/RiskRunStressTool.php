<?php
declare(strict_types=1);
namespace App\Infrastructure\Automation;
use Domains\CapitalMarkets\Application\Service\CapitalRiskService;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\{ToolDefinition,ToolEffect,ToolInvocation,ToolResult};
final readonly class RiskRunStressTool implements ToolInterface {
 public function __construct(private CapitalRiskService $service){}
 public function definition():ToolDefinition{return new ToolDefinition('risk.runstress','Run deterministic portfolio stress scenario without execution.',['type'=>'object','required'=>['agent_name','shocks'],'properties'=>['agent_name'=>['type'=>'string'],'scenario_id'=>['type'=>'string'],'name'=>['type'=>'string'],'shocks'=>['type'=>'object']]],['type'=>'object'],ToolEffect::READ);}
 public function invoke(ToolInvocation $i):ToolResult{return ToolResult::success($this->service->runStress($i->organizationId()->value(),$i->input()));}
}
