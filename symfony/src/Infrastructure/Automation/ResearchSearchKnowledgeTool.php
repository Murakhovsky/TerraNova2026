<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolResult;

final readonly class ResearchSearchKnowledgeTool implements ToolInterface
{
    public function __construct(private ResearchLabRepositoryInterface $research){}

    public function definition():ToolDefinition
    {
        return new ToolDefinition(
            'research.searchknowledge',
            'Search reusable Capital Markets research knowledge, including negative findings.',
            ['type'=>'object','properties'=>['query'=>['type'=>'string'],'limit'=>['type'=>'integer']]],
            ['type'=>'object'],
            ToolEffect::READ,
        );
    }

    public function invoke(ToolInvocation $invocation):ToolResult
    {
        $input=$invocation->input();
        $query=strtolower(trim((string)($input['query']??'')));
        $rows=$this->research->listKnowledge($invocation->organizationId()->value(),max(1,min(500,(int)($input['limit']??100))));
        if($query!==''){
            $rows=array_values(array_filter($rows,static fn(array $row):bool=>str_contains(
                strtolower(json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:''),$query
            )));
        }
        return ToolResult::success(['knowledge'=>$rows,'count'=>count($rows)]);
    }
}
