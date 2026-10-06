<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Domains\CapitalMarkets\Application\Service\ResearchLabService;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolResult;

final readonly class ResearchCreateExperimentDraftTool implements ToolInterface
{
    public function __construct(private ResearchLabService $lab){}

    public function definition():ToolDefinition
    {
        return new ToolDefinition(
            'research.createexperimentdraft',
            'Create an immutable experiment draft against an already frozen dataset and strategy version.',
            [
                'type'=>'object',
                'required'=>['experiment_id','hypothesis_id','dataset_id','strategy_version_id','experiment_type','success_criteria','failure_criteria'],
                'properties'=>[
                    'experiment_id'=>['type'=>'string'],'hypothesis_id'=>['type'=>'string'],'dataset_id'=>['type'=>'string'],
                    'strategy_version_id'=>['type'=>'string'],'experiment_type'=>['type'=>'string'],'title'=>['type'=>'string'],
                    'objective'=>['type'=>'string'],'parameters'=>['type'=>'object'],'success_criteria'=>['type'=>'object'],
                    'failure_criteria'=>['type'=>'object'],'start_period'=>['type'=>'string'],'end_period'=>['type'=>'string'],
                    'execution_model_version'=>['type'=>'string'],'risk_configuration_version'=>['type'=>'string']
                ],
            ],
            ['type'=>'object'],
            ToolEffect::WRITE,
        );
    }

    public function invoke(ToolInvocation $invocation):ToolResult
    {
        $input=$invocation->input();
        $input['status']='DRAFT';
        $input['title']=$input['title']??$input['experiment_id'];
        $input['objective']=$input['objective']??'Test hypothesis under predefined criteria';
        $input['parameters']=$input['parameters']??[];
        $input['execution_model_version']=$input['execution_model_version']??'current-paper';
        $input['risk_configuration_version']=$input['risk_configuration_version']??'current';
        $input['created_by']='research-agent';
        $record=$this->lab->createExperiment($invocation->organizationId()->value(),$input);
        return ToolResult::success(['experiment'=>$record],['draft_only'=>true,'run_started'=>false]);
    }
}
