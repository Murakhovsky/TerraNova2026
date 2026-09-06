<?php
declare(strict_types=1);
namespace Domains\Sales\Automation\Action;
use Domains\Sales\Application\DTO\ChangeDealStageCommand;
use Domains\Sales\Application\UseCase\ChangeDealStage;
use Kernel\Action\{Action,ExecutionResult};
use Kernel\Action\Contract\ActionHandlerInterface;
final readonly class ChangeDealStageHandler implements ActionHandlerInterface
{
    public const TYPE='sales.change_stage';
    public function __construct(private ChangeDealStage $changeStage){}
    public function supports(string $actionType):bool{return $actionType===self::TYPE;}
    public function execute(Action $action):ExecutionResult{if($action->targetType!=='deal'||$action->targetId===null)return ExecutionResult::failure('Deal target is required.');$stageId=trim((string)($action->parameters['stage_id']??''));if($stageId==='')return ExecutionResult::failure('Canonical stage_id is required.');$result=$this->changeStage->execute(new ChangeDealStageCommand($action->organizationId,$action->targetId,$stageId,$action->sourceType,$action->sourceId,$action->correlationId));return $result->successful?ExecutionResult::success(['deal_id'=>$action->targetId,'previous_stage_id'=>$result->previousStageId,'stage_id'=>$result->stageId,'changed'=>$result->changed],['deal_stage_changes'=>$result->changed?1:0]):ExecutionResult::failure($result->reason??'Stage transition failed.');}
}
