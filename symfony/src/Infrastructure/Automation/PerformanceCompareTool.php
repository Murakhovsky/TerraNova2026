<?php
declare(strict_types=1);
namespace App\Infrastructure\Automation;
use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\{ToolDefinition,ToolEffect,ToolInvocation,ToolResult};
final readonly class PerformanceCompareTool implements ToolInterface {
 public function __construct(private ResearchLabRepositoryInterface $research){}
 public function definition():ToolDefinition{return new ToolDefinition('performance.compare','Compare Research Lab scorecards and paper performance evidence.',['type'=>'object','required'=>['agent_name'],'properties'=>['agent_name'=>['type'=>'string'],'strategy_version_ids'=>['type'=>'array']]],['type'=>'object'],ToolEffect::READ);}
 public function invoke(ToolInvocation $i):ToolResult{$ids=array_map('strval',(array)($i->input()['strategy_version_ids']??[]));$rows=$this->research->listScorecards($i->organizationId()->value(),500);if($ids!==[])$rows=array_values(array_filter($rows,fn($r)=>in_array((string)($r['strategy_version_id']??''),$ids,true)));return ToolResult::success(['scorecards'=>$rows]);}
}
