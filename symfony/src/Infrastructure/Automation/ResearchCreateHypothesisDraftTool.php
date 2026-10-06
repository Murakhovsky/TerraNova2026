<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Domains\CapitalMarkets\Application\Service\ResearchLabService;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolResult;

final readonly class ResearchCreateHypothesisDraftTool implements ToolInterface
{
    public function __construct(private ResearchLabService $lab){}

    public function definition():ToolDefinition
    {
        return new ToolDefinition(
            'research.createhypothesis',
            'Create a measurable Capital Markets ResearchHypothesis draft. This never validates or promotes a strategy.',
            [
                'type'=>'object',
                'required'=>['agent_name','hypothesis_id','code','title','description','economic_reason','edge_source','expected_behavior','required_data','success_criteria','failure_criteria'],
                'properties'=>[
                    'agent_name'=>['type'=>'string'],'hypothesis_id'=>['type'=>'string'],'code'=>['type'=>'string'],'title'=>['type'=>'string'],'description'=>['type'=>'string'],
                    'economic_reason'=>['type'=>'string'],'edge_source'=>['type'=>'string'],'expected_behavior'=>['type'=>'string'],
                    'required_data'=>['type'=>'array'],'success_criteria'=>['type'=>'object'],'failure_criteria'=>['type'=>'object'],
                    'instrument_families'=>['type'=>'array'],'markets'=>['type'=>'array'],'venues'=>['type'=>'array'],
                    'required_capabilities'=>['type'=>'array'],'risk_assumptions'=>['type'=>'object'],'capital_assumptions'=>['type'=>'object'],
                    'time_horizon'=>['type'=>'string'],'priority'=>['type'=>'string']
                ],
            ],
            ['type'=>'object'],
            ToolEffect::WRITE,
        );
    }

    public function invoke(ToolInvocation $invocation):ToolResult
    {
        $input=$invocation->input();
        unset($input['agent_name']);
        $input['status']='DRAFT';
        $input['priority']=$input['priority']??'P2';
        $input['instrument_families']=$input['instrument_families']??[];
        $input['markets']=$input['markets']??[];
        $input['venues']=$input['venues']??[];
        $input['required_capabilities']=$input['required_capabilities']??[];
        $input['risk_assumptions']=$input['risk_assumptions']??[];
        $input['capital_assumptions']=$input['capital_assumptions']??[];
        $input['time_horizon']=$input['time_horizon']??'UNSPECIFIED';
        $input['created_by']='research-agent';
        $record=$this->lab->createHypothesis($invocation->organizationId()->value(),$input);
        return ToolResult::success(['hypothesis'=>$record],['draft_only'=>true,'live_authority'=>false]);
    }
}
