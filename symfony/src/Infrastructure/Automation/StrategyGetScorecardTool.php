<?php
declare(strict_types=1);
namespace App\Infrastructure\Automation;
use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\{ToolDefinition,ToolEffect,ToolInvocation,ToolResult};
final readonly class StrategyGetScorecardTool implements ToolInterface {
 public function __construct(private ResearchLabRepositoryInterface $research){}
 public function definition():ToolDefinition{return new ToolDefinition('strategy.getscorecard','Read Strategy Scorecards from Research Lab.',['type'=>'object','required'=>['agent_name'],'properties'=>['agent_name'=>['type'=>'string'],'strategy_version_id'=>['type'=>'string']]],['type'=>'object'],ToolEffect::READ);}
 public function invoke(ToolInvocation $i):ToolResult{$p=$i->input();$id=(string)($p['strategy_version_id']??'');$rows=$this->research->listScorecards($i->organizationId()->value(),500);if($id!=='')$rows=array_values(array_filter($rows,fn($r)=>(string)($r['strategy_version_id']??'')===$id));return ToolResult::success(['scorecards'=>$rows]);}
}
