<?php
declare(strict_types=1);
namespace Domains\Sales\Application\UseCase;
use Domains\Sales\Application\Contract\{DealStageRepositoryInterface,PipelineRepositoryInterface};
use Domains\Sales\Application\DTO\{ChangeDealStageCommand,ChangeDealStageResult};
use Domains\Sales\Automation\Event\{DealStageChanged,SalesEventType};
use Domains\Sales\Domain\Policy\StageTransitionPolicy;
use Kernel\Event\{DomainEvent,EventBus,EventMetadata};
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class ChangeDealStage
{
    public function __construct(private DealStageRepositoryInterface $deals,private PipelineRepositoryInterface $pipelines,private StageTransitionPolicy $policy,private EventBus $events,private TransactionManagerInterface $transactions){}
    public function execute(ChangeDealStageCommand $command):ChangeDealStageResult
    {
        $deal=$this->deals->getForStageChange($command->organizationId,$command->dealId);if($deal===null)return ChangeDealStageResult::failure('Deal was not found in the current organization.');
        $pipeline=$this->pipelines->getPipeline($command->organizationId,(string)$deal['pipeline_id']);if($pipeline===null)return ChangeDealStageResult::failure('Deal pipeline was not found.');
        $current=$this->pipelines->getStage($command->organizationId,(string)$deal['stage_id']);$target=$this->pipelines->getStage($command->organizationId,$command->targetStageId);if($current===null||$target===null)return ChangeDealStageResult::failure('Current or target stage was not found in the current organization.');
        $transition=$current->id===$target->id?null:$this->pipelines->getTransition($command->organizationId,$pipeline->id,$current->id,$target->id);$validation=$this->policy->evaluate($deal,$pipeline,$current,$target,$transition);if(!$validation->allowed)return ChangeDealStageResult::failure($validation->reason);if($validation->noOp)return ChangeDealStageResult::success($current->id,$target->id,false);
        return $this->transactions->transactional(function()use($command,$pipeline,$current,$target,$validation){$changed=$this->deals->changeStage($command->organizationId,$command->dealId,$pipeline->id,$current->id,$target->id,$target->code,$target->probabilityDefault,$target->isTerminal,$target->isWon,$target->isLost);if(!$changed)return ChangeDealStageResult::failure('concurrent_stage_change');$meta=new EventMetadata($command->correlationId,null,$command->actorType,$command->actorId);$this->events->publish(DealStageChanged::create(bin2hex(random_bytes(16)),$command->organizationId,$command->dealId,$current->code,$target->code,$meta));$terminalType=$target->isWon?SalesEventType::DEAL_WON:($target->isLost?SalesEventType::DEAL_LOST:null);if($terminalType!==null)$this->events->publish(new DomainEvent(bin2hex(random_bytes(16)),$command->organizationId,$terminalType,'deal',$command->dealId,['pipeline_id'=>$pipeline->id,'stage_id'=>$target->id,'stage_code'=>$target->code],$meta,new \DateTimeImmutable()));return ChangeDealStageResult::success($current->id,$target->id,true,$validation->requiresApproval);});
    }
}
