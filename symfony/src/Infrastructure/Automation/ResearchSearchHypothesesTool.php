<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolResult;

final readonly class ResearchSearchHypothesesTool implements ToolInterface
{
    public function __construct(private ResearchLabRepositoryInterface $research){}

    public function definition():ToolDefinition
    {
        return new ToolDefinition(
            'research.searchhypotheses',
            'Search formal Capital Markets research hypotheses, including rejected and active records.',
            ['type'=>'object','properties'=>[
                'status'=>['type'=>'string'],
                'edge_source'=>['type'=>'string'],
                'query'=>['type'=>'string'],
                'limit'=>['type'=>'integer'],
            ]],
            ['type'=>'object'],
            ToolEffect::READ,
        );
    }

    public function invoke(ToolInvocation $invocation):ToolResult
    {
        $input=$invocation->input();
        $limit=max(1,min(500,(int)($input['limit']??100)));
        $rows=$this->research->listHypotheses($invocation->organizationId()->value(),$limit);
        $status=strtoupper(trim((string)($input['status']??'')));
        $edge=strtoupper(trim((string)($input['edge_source']??'')));
        $query=strtolower(trim((string)($input['query']??'')));
        $rows=array_values(array_filter($rows,static function(array $row)use($status,$edge,$query):bool{
            if($status!==''&&strtoupper((string)($row['status']??''))!==$status)return false;
            if($edge!==''&&strtoupper((string)($row['edge_source']??''))!==$edge)return false;
            if($query!==''&&!str_contains(strtolower(json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:''),$query))return false;
            return true;
        }));
        return ToolResult::success(['hypotheses'=>$rows,'count'=>count($rows)]);
    }
}
