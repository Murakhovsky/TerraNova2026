<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;
use Domains\Sales\Model\{PipelineDefinition,PipelineStageDefinition,PipelineTransitionDefinition};
interface PipelineRepositoryInterface
{
    public function getPipeline(string $organizationId,string $pipelineId):?PipelineDefinition;
    public function getDefaultPipeline(string $organizationId):?PipelineDefinition;
    public function getStage(string $organizationId,string $stageId):?PipelineStageDefinition;
    public function findStageByCode(string $organizationId,string $pipelineId,string $stageCode):?PipelineStageDefinition;
    public function getTransition(string $organizationId,string $pipelineId,string $fromStageId,string $toStageId):?PipelineTransitionDefinition;
}
