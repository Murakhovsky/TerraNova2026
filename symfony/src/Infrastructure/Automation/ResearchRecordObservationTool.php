<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolResult;

final readonly class ResearchRecordObservationTool implements ToolInterface
{
    public function __construct(private CapitalMarketsTradingRepositoryInterface $trading){}
    public function definition():ToolDefinition{return new ToolDefinition(
        'research.recordobservation','Record a non-authoritative research observation linked to a hypothesis. This does not validate or promote a strategy.',
        ['type'=>'object','required'=>['agent_name','hypothesis','stage','observed_at','evidence_json'],'properties'=>[
            'agent_name'=>['type'=>'string'],'hypothesis'=>['type'=>'string'],'stage'=>['type'=>'string'],
            'observed_at'=>['type'=>'string'],'evidence_json'=>['type'=>'string'],
        ]],['type'=>'object'],ToolEffect::WRITE
    );}
    public function invoke(ToolInvocation $invocation):ToolResult
    {
        $input=$invocation->input();
        try{$evidence=json_decode((string)$input['evidence_json'],true,512,JSON_THROW_ON_ERROR);}
        catch(\JsonException $e){return ToolResult::failure('evidence_json is malformed.');}
        if(!is_array($evidence))return ToolResult::failure('evidence_json must encode an object or array.');
        $hypothesis=strtoupper(trim((string)$input['hypothesis']));$stage=strtoupper(trim((string)$input['stage']));
        $fingerprint=hash('sha256',$hypothesis.'|'.$stage.'|'.(string)$input['observed_at'].'|'.json_encode($evidence,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        $id='research-observation-'.substr($fingerprint,0,32);
        $this->trading->saveHypothesisObservation(
            $invocation->organizationId()->value(),$id,$hypothesis,$stage,(string)$input['observed_at'],$fingerprint,
            ['source'=>'research-agent','evidence'=>$evidence]
        );
        return ToolResult::success(['observation_id'=>$id,'fingerprint'=>$fingerprint],['authority'=>'observation_only']);
    }
}
