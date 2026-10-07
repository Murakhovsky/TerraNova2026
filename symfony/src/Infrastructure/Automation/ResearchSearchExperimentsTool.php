<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolResult;

final readonly class ResearchSearchExperimentsTool implements ToolInterface
{
    public function __construct(private ResearchLabRepositoryInterface $research){}
    public function definition():ToolDefinition{return new ToolDefinition(
        'research.searchexperiments','Search immutable ResearchExperiment specifications and statuses.',
        ['type'=>'object','required'=>['agent_name'],'properties'=>[
            'agent_name'=>['type'=>'string'],'hypothesis_id'=>['type'=>'string'],'status'=>['type'=>'string'],'limit'=>['type'=>'integer'],
        ]],['type'=>'object'],ToolEffect::READ
    );}
    public function invoke(ToolInvocation $invocation):ToolResult
    {
        $input=$invocation->input();$hypothesis=trim((string)($input['hypothesis_id']??''));$status=strtoupper(trim((string)($input['status']??'')));
        $rows=$this->research->listExperiments($invocation->organizationId()->value(),$hypothesis!==''?$hypothesis:null,max(1,min(500,(int)($input['limit']??100))));
        if($status!=='')$rows=array_values(array_filter($rows,static fn(array $r):bool=>strtoupper((string)($r['status']??''))===$status));
        return ToolResult::success(['experiments'=>$rows,'count'=>count($rows)]);
    }
}
