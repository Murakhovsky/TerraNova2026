<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';
use Kernel\Action\{Action,ActionProposal,ActionStatus,ExecutionResult};
use Kernel\Action\Contract\{ActionHandlerInterface,ActionRepositoryInterface};
use Kernel\Action\Service\{ActionExecutor,ActionService};
use Kernel\Approval\{Approval,ApprovalStatus};
use Kernel\Approval\Contract\ApprovalRepositoryInterface;
use Kernel\Policy\{ActionPolicy,PolicyDecision,PolicyEvaluation};
use Kernel\Policy\Contract\{PolicyEvaluationRepositoryInterface,PolicyRepositoryInterface};
use Kernel\Policy\Service\{ActionPolicyService,PolicyEngine};
use Kernel\Rule\Service\ConditionEvaluator;
use Kernel\Transaction\Contract\TransactionManagerInterface;

$actions=new class implements ActionRepositoryInterface{public array $items=[];public function save(Action $a):Action{foreach($this->items as $old)if($old->organizationId===$a->organizationId&&$old->idempotencyKey===$a->idempotencyKey)return $old;return $this->items[$a->id]=$a;}public function find(string $o,string $id):?Action{return isset($this->items[$id])&&$this->items[$id]->organizationId===$o?$this->items[$id]:null;}public function existsByIdempotencyKey(string $o,string $k):bool{return false;}public function transition(string $o,string $id,ActionStatus $from,ActionStatus $to):bool{$a=$this->find($o,$id);if(!$a||$a->status!==$from)return false;$a->transitionTo($to);return true;}public function claimNext(string $w):?Action{return null;}public function claim(string $o,string $id,string $w):?Action{return null;}public function finish(Action $a,ExecutionResult $r):void{}public function requeueStale(int $s):int{return 0;}};
$policyRepo=new class implements PolicyRepositoryInterface{public function activeFor(string $o,string $type):array{return [new ActionPolicy('human-only',$o,$type,[],PolicyDecision::HumanOnly,1)];}};
$evaluations=new class implements PolicyEvaluationRepositoryInterface{public array $items=[];public function save(Action $a,PolicyEvaluation $e,array $c):void{$this->items[]=$e;}};
$approvals=new class implements ApprovalRepositoryInterface{public function createFor(Action $a,string $t,string $id,string $r):Approval{throw new RuntimeException('HUMAN_ONLY must not create approval.');}public function findPending(string $o,string $id):?Approval{return null;}public function decide(string $o,string $id,ApprovalStatus $d,string $u,?string $n):bool{return false;}};
$tx=new class implements TransactionManagerInterface{public function transactional(callable $op):mixed{return $op();}public function isActive():bool{return true;}public function afterCommit(callable $c):void{$c();}};
$actionService=new ActionService($actions,new ActionExecutor([new class implements ActionHandlerInterface{public function supports(string $t):bool{return true;}public function execute(Action $a):ExecutionResult{return ExecutionResult::success();}}]));
$service=new ActionPolicyService($actionService,$policyRepo,$evaluations,$approvals,new PolicyEngine(new ConditionEvaluator()),$tx);
$automated=$service->submit('org',new ActionProposal('sales.delete_deal','deal','1',[],'AGENT','run','AUTO','HIGH','auto-key'),'c1');if($automated->status!==ActionStatus::Rejected)throw new RuntimeException('Automated HUMAN_ONLY action became executable.');
$human=$service->submit('org',new ActionProposal('sales.delete_deal','deal','1',[],'USER','7','HUMAN_ONLY','HIGH','human-key'),'c2');if($human->status!==ActionStatus::Queued)throw new RuntimeException('Human-originated HUMAN_ONLY action was not permitted.');
if(count($evaluations->items)!==2||$evaluations->items[0]->decision!==PolicyDecision::HumanOnly)throw new RuntimeException('HUMAN_ONLY evaluation was not persisted.');
echo "HUMAN_ONLY policy lifecycle passed.\n";
