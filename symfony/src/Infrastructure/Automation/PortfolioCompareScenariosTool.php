<?php
declare(strict_types=1);
namespace App\Infrastructure\Automation;
use Domains\CapitalMarkets\Application\Service\CapitalRiskService;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\{ToolDefinition,ToolEffect,ToolInvocation,ToolResult};
final readonly class PortfolioCompareScenariosTool implements ToolInterface {
 public function __construct(private CapitalRiskService $service){}
 public function definition():ToolDefinition{return new ToolDefinition('portfolio.comparescenarios','Compare multiple deterministic stress scenarios.',['type'=>'object','required'=>['agent_name','scenarios'],'properties'=>['agent_name'=>['type'=>'string'],'scenarios'=>['type'=>'array']]],['type'=>'object'],ToolEffect::READ);}
 public function invoke(ToolInvocation $i):ToolResult{$out=[];foreach(array_slice((array)($i->input()['scenarios']??[]),0,6) as $s)if(is_array($s))$out[]=$this->service->runStress($i->organizationId()->value(),$s);return ToolResult::success(['scenarios'=>$out]);}
}
