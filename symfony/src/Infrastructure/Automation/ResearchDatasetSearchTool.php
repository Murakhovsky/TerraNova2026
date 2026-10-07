<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolResult;

final readonly class ResearchDatasetSearchTool implements ToolInterface
{
    public function __construct(private ResearchLabRepositoryInterface $research){}
    public function definition():ToolDefinition{return new ToolDefinition(
        'dataset.search','Search frozen Capital Markets ResearchDataset snapshots.',
        ['type'=>'object','required'=>['agent_name'],'properties'=>[
            'agent_name'=>['type'=>'string'],'query'=>['type'=>'string'],'quality_status'=>['type'=>'string'],'limit'=>['type'=>'integer'],
        ]],['type'=>'object'],ToolEffect::READ
    );}
    public function invoke(ToolInvocation $invocation):ToolResult
    {
        $input=$invocation->input();$query=strtolower(trim((string)($input['query']??'')));$quality=strtoupper(trim((string)($input['quality_status']??'')));
        $rows=$this->research->listDatasets($invocation->organizationId()->value(),max(1,min(500,(int)($input['limit']??100))));
        $rows=array_values(array_filter($rows,static function(array $r)use($query,$quality):bool{
            if($quality!==''&&strtoupper((string)($r['quality_status']??''))!==$quality)return false;
            return $query===''||str_contains(strtolower(json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:''),$query);
        }));
        return ToolResult::success(['datasets'=>$rows,'count'=>count($rows)]);
    }
}
