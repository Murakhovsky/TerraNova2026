<?php
declare(strict_types=1);
namespace App\Infrastructure\Automation;
use Domains\CapitalMarkets\Application\Contract\CapitalRiskRepositoryInterface;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\{ToolDefinition,ToolEffect,ToolInvocation,ToolResult};
final readonly class PortfolioGetRiskTool implements ToolInterface {
 public function __construct(private CapitalRiskRepositoryInterface $repository){}
 public function definition():ToolDefinition{return new ToolDefinition('portfolio.getrisk','Read latest portfolio risk snapshot and headroom.',['type'=>'object','required'=>['agent_name'],'properties'=>['agent_name'=>['type'=>'string']]],['type'=>'object'],ToolEffect::READ);}
 public function invoke(ToolInvocation $i):ToolResult{return ToolResult::success(['risk'=>$this->repository->latestRiskSnapshot($i->organizationId()->value(),'paper-master'),'envelope'=>$this->repository->latestRiskEnvelope($i->organizationId()->value(),'paper-master')]);}
}
