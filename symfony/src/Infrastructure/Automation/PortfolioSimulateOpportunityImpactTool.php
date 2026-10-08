<?php
declare(strict_types=1);
namespace App\Infrastructure\Automation;
use Domains\CapitalMarkets\Application\Service\CapitalRiskService;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\{ToolDefinition,ToolEffect,ToolInvocation,ToolResult};
final readonly class PortfolioSimulateOpportunityImpactTool implements ToolInterface {
 public function __construct(private CapitalRiskService $service){}
 public function definition():ToolDefinition{return new ToolDefinition('portfolio.simulateopportunityimpact','Simulate portfolio impact of one opportunity without execution.',['type'=>'object','required'=>['agent_name','opportunity_id','capital'],'properties'=>['agent_name'=>['type'=>'string'],'opportunity_id'=>['type'=>'string'],'capital'=>['type'=>'string']]],['type'=>'object'],ToolEffect::READ);}
 public function invoke(ToolInvocation $i):ToolResult{$p=$i->input();return ToolResult::success($this->service->simulateOpportunityImpact($i->organizationId()->value(),(string)$p['opportunity_id'],(string)$p['capital']));}
}
