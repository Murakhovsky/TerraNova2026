<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolResult;

final readonly class ResearchGetResultTool implements ToolInterface
{
    public function __construct(private ResearchLabRepositoryInterface $research){}
    public function definition():ToolDefinition{return new ToolDefinition(
        'research.getresult','Get the immutable ResearchResult for one experiment.',
        ['type'=>'object','required'=>['agent_name','experiment_id'],'properties'=>[
            'agent_name'=>['type'=>'string'],'experiment_id'=>['type'=>'string'],
        ]],['type'=>'object'],ToolEffect::READ
    );}
    public function invoke(ToolInvocation $invocation):ToolResult
    {
        $id=trim((string)($invocation->input()['experiment_id']??''));
        $row=$this->research->getResultForExperiment($invocation->organizationId()->value(),$id);
        return $row===null?ToolResult::failure('Research result not found.'):ToolResult::success(['result'=>$row]);
    }
}
