<?php
declare(strict_types=1);
namespace App\Infrastructure\Automation;
use Domains\CapitalMarkets\Application\Service\CapitalRiskService;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\{ToolDefinition,ToolEffect,ToolInvocation,ToolResult};
final readonly class PortfolioGetStateTool implements ToolInterface {
 public function __construct(private CapitalRiskService $service){}
 public function definition():ToolDefinition{return new ToolDefinition('portfolio.getstate','Read current Capital Markets portfolio state.',['type'=>'object','required'=>['agent_name'],'properties'=>['agent_name'=>['type'=>'string']]],['type'=>'object'],ToolEffect::READ);}
 public function invoke(ToolInvocation $i):ToolResult{return ToolResult::success($this->service->workspace($i->organizationId()->value()));}
}
