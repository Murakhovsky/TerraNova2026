<?php
declare(strict_types=1);
namespace Domains\Sales\Domain\Policy;
use Domains\Sales\Model\{PipelineDefinition,PipelineStageDefinition,PipelineTransitionDefinition};
final class StageTransitionPolicy
{
    /**
     * Validates Sales topology. Required approval cannot be inferred from actor type;
     * the use case blocks that edge until verified canonical approval is available.
     */
    public function evaluate(array $deal,PipelineDefinition $pipeline,PipelineStageDefinition $current,PipelineStageDefinition $target,?PipelineTransitionDefinition $transition):StageTransitionResult
    {
        if((string)($deal['pipeline_id']??'')!==$pipeline->id)return StageTransitionResult::denied('Deal does not belong to the selected pipeline.');
        $ids=array_map(fn($s)=>$s->id,$pipeline->stages);if(!in_array($current->id,$ids,true)||!in_array($target->id,$ids,true))return StageTransitionResult::denied('Stage belongs to another pipeline.');
        if($current->id===$target->id)return StageTransitionResult::allowed(null,true);
        if($current->isTerminal)return StageTransitionResult::denied('A terminal stage cannot be left by a regular transition.');
        if($transition===null)return StageTransitionResult::denied('Pipeline transition is not configured.');
        if($transition->pipelineId!==$pipeline->id||$transition->fromStageId!==$current->id||$transition->toStageId!==$target->id)return StageTransitionResult::denied('Pipeline transition does not match the requested stages.');
        // Until typed predicates exist, do not silently ignore configured conditions.
        if ($transition->conditions!==[]) return StageTransitionResult::denied('Configured transition conditions are unsupported.');
        if (in_array((string)($deal['status']??''),['closed','lost'],true)) return StageTransitionResult::denied('Terminal deal cannot be reopened by a regular transition.');
        return StageTransitionResult::allowed($transition);
    }
}
