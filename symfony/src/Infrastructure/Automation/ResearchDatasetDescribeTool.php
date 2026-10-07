<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolResult;

final readonly class ResearchDatasetDescribeTool implements ToolInterface
{
    public function __construct(private ResearchLabRepositoryInterface $research){}
    public function definition():ToolDefinition{return new ToolDefinition(
        'dataset.describe','Describe one immutable frozen ResearchDataset, including sources, period, quality and snapshot hash.',
        ['type'=>'object','required'=>['agent_name','dataset_id'],'properties'=>[
            'agent_name'=>['type'=>'string'],'dataset_id'=>['type'=>'string'],
        ]],['type'=>'object'],ToolEffect::READ
    );}
    public function invoke(ToolInvocation $invocation):ToolResult
    {
        $id=trim((string)($invocation->input()['dataset_id']??''));
        $row=$this->research->getDataset($invocation->organizationId()->value(),$id);
        return $row===null?ToolResult::failure('Research dataset not found.'):ToolResult::success(['dataset'=>$row]);
    }
}
