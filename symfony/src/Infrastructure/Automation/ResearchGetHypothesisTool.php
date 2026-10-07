<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolResult;

final readonly class ResearchGetHypothesisTool implements ToolInterface
{
    public function __construct(private ResearchLabRepositoryInterface $research){}
    public function definition():ToolDefinition{return new ToolDefinition(
        'research.gethypothesis','Get the latest immutable revision of one formal Capital Markets research hypothesis.',
        ['type'=>'object','required'=>['agent_name','hypothesis_id'],'properties'=>[
            'agent_name'=>['type'=>'string'],'hypothesis_id'=>['type'=>'string'],
        ]],['type'=>'object'],ToolEffect::READ
    );}
    public function invoke(ToolInvocation $invocation):ToolResult
    {
        $id=trim((string)($invocation->input()['hypothesis_id']??''));
        $row=$this->research->getHypothesis($invocation->organizationId()->value(),$id);
        return $row===null?ToolResult::failure('Research hypothesis not found.'):ToolResult::success(['hypothesis'=>$row]);
    }
}
