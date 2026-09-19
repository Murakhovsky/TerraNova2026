<?php
declare(strict_types=1);
namespace App\Application\Operations\Command;
use DomainException;
use Kernel\Action\ActionStatus;
use Kernel\Action\Service\ActionService;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Approval\Contract\ApprovalRepositoryInterface;
use Kernel\Approval\Service\ApprovalService;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Handler\ActionExecutionJobHandler;
final readonly class OperationsMutationCommandHandler implements CommandHandlerInterface
{
 public function __construct(private ApprovalRepositoryInterface $approvalRepository,private ApprovalService $approvals,private ActionService $actions,private JobQueueInterface $queue){}
 public function __invoke(OperationsMutationCommand $c):array
 {
  if($c->organizationId===''||$c->actorId<=0)throw new DomainException('Authenticated actor is invalid.');
  if(!preg_match('/^[a-f0-9]{32}$/',$c->resourceId))throw new DomainException('Invalid operations resource id.');
  return match($c->operation){OperationsMutationCommand::APPROVE=>$this->approval($c,true),OperationsMutationCommand::REJECT=>$this->approval($c,false),OperationsMutationCommand::EXECUTE_ACTION=>$this->action($c,true),OperationsMutationCommand::DISMISS_ACTION=>$this->action($c,false),default=>throw new DomainException('Unsupported operations mutation.')};
 }
 private function approval(OperationsMutationCommand $c,bool $approve):array
 {
  $pending=$this->approvalRepository->findPending($c->organizationId,$c->resourceId);if($pending===null)throw new DomainException('Pending approval not found.');
  $note=($n=trim((string)($c->input['note']??'')))!==''?$n:null;
  if($approve){$this->approvals->approve($c->organizationId,$c->resourceId,(string)$c->actorId,$note);return ['status'=>'APPROVED','action_id'=>$pending->actionId];}
  $this->approvals->reject($c->organizationId,$c->resourceId,(string)$c->actorId,$note);return ['status'=>'REJECTED','action_id'=>$pending->actionId];
 }
 private function action(OperationsMutationCommand $c,bool $execute):array
 {
  $a=$this->actions->find($c->organizationId,$c->resourceId);if($a===null)throw new DomainException('Action not found.');
  if(!$execute){if($a->status!==ActionStatus::Proposed)throw new DomainException('Only a proposed action can be dismissed directly.');$this->actions->reject($c->organizationId,$c->resourceId);return ['status'=>'REJECTED'];}
  if($a->status===ActionStatus::PendingApproval)throw new DomainException('Action requires approval first.');
  if(in_array($a->status,[ActionStatus::Completed,ActionStatus::Rejected,ActionStatus::Running],true))throw new DomainException('Action cannot be executed from its current status.');
  if(in_array($a->status,[ActionStatus::Proposed,ActionStatus::Failed],true)){$this->actions->queue($c->organizationId,$c->resourceId);$a=$this->actions->find($c->organizationId,$c->resourceId)??$a;}
  $job=$this->queue->enqueue($c->organizationId,ActionExecutionJobHandler::TYPE,['action_id'=>$c->resourceId],$a->correlationId?:$c->correlationId,'action-execution:'.$c->resourceId,5,120);
  return ['status'=>'QUEUED','job_id'=>$job];
 }
}
