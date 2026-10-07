<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolResult;

final readonly class ResearchCompareResultsTool implements ToolInterface
{
    public function __construct(private ResearchLabRepositoryInterface $research){}
    public function definition():ToolDefinition{return new ToolDefinition(
        'research.compareresults','Return canonical immutable research results side by side for comparison; no AI-computed financial values are created.',
        ['type'=>'object','required'=>['agent_name','experiment_ids'],'properties'=>[
            'agent_name'=>['type'=>'string'],'experiment_ids'=>['type'=>'array','items'=>['type'=>'string']],
        ]],['type'=>'object'],ToolEffect::READ
    );}
    public function invoke(ToolInvocation $invocation):ToolResult
    {
        $ids=array_values(array_unique(array_map('strval',(array)($invocation->input()['experiment_ids']??[]))));
        if(count($ids)<2||count($ids)>10)return ToolResult::failure('Compare results requires between 2 and 10 experiment_ids.');
        $rows=[];
        foreach($ids as $id){
            $result=$this->research->getResultForExperiment($invocation->organizationId()->value(),$id);
            if($result!==null)$rows[]=['experiment_id'=>$id,'result'=>$result];
        }
        return ToolResult::success(['results'=>$rows,'requested_count'=>count($ids),'found_count'=>count($rows)]);
    }
}
