<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Growth\Application\Contract\GrowthEngagementActivationProfileRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Service\GrowthEngagementActivationService;
use Domains\Growth\Automation\Policy\GrowthPolicyCatalog;
use Domains\Growth\Domain\EngagementActivationMode;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Policy\PolicyDecision;
use Kernel\Policy\Service\PolicyEngine;
use Kernel\Rule\Service\ConditionEvaluator;
use Kernel\Transaction\Contract\TransactionManagerInterface;

function expectGrowthV0410(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$profiles=new class implements GrowthEngagementActivationProfileRepositoryInterface {
    public array $rows=[];
    public function latest(string $organizationId):?array{$rows=$this->rows[$organizationId]??[];return $rows===[]?null:$rows[array_key_last($rows)];}
    public function append(array $profile):void{$this->rows[$profile['organization_id']][]=$profile;}
};
$executions=new class implements GrowthEngagementExecutionRepositoryInterface {
    public int $locks=0;
    public function byRecommendation(string $organizationId,string $recommendationId):?array{return null;}
    public function byActionId(string $organizationId,string $actionId):?array{return null;}
    public function createOrVerify(string $organizationId,string $executionId,string $candidateId,string $recommendationId,string $targetDomain,string $targetReferenceType,string $targetReferenceId,string $actionId,string $actionType,string $channel,string $payloadFingerprint,int $actorId):void{}
    public function latestForCandidate(string $organizationId,string $candidateId):?array{return null;}
    public function lockPreHandoffCapacity(string $organizationId):void{$this->locks++;}
    public function countPreHandoffSince(string $organizationId,string $since):int{return 0;}
    public function countPreHandoffSinceByChannel(string $organizationId,string $channel,string $since):int{return 0;}
    public function latestPreHandoffForTarget(string $organizationId,string $targetReferenceId):?array{return null;}
};
$receipts=new class implements GrowthMutationReceiptInterface {
    public array $claims=[];
    public function claim(string $organizationId,string $operation,string $idempotencyKey,string $fingerprint):bool{
        $key=$organizationId.':'.$operation.':'.$idempotencyKey;
        if(isset($this->claims[$key])){if($this->claims[$key]!==$fingerprint)throw new InvalidArgumentException('idempotency conflict');return false;}
        $this->claims[$key]=$fingerprint;return true;
    }
};
$transactions=new class implements TransactionManagerInterface {
    public function transactional(callable $operation):mixed{return $operation();}
    public function isActive():bool{return true;}
    public function afterCommit(callable $callback):void{$callback();}
};
$eventStore=new class implements EventStoreInterface {
    public array $items=[];
    public function append(DomainEvent $event):void{$this->items[]=$event;}
    public function find(string $eventId):?DomainEvent{return null;}
    public function findByAggregate(string $organizationId,string $aggregateType,string $aggregateId,int $limit=100):array{return [];}
};
$audit=new class implements AuditRepositoryInterface {
    public array $entries=[];
    public function append(AuditEntry $entry):void{$this->entries[]=$entry;}
};

$service=new GrowthEngagementActivationService($profiles,$executions,$receipts,$transactions,new EventBus($eventStore,$transactions),$audit);
$defaults=$service->view('org-1');
expectGrowthV0410(($defaults['effective']['channel_modes']??null)===['email'=>'approval_required','linkedin'=>'approval_required','phone'=>'approval_required'],'Safe defaults must require approval.');

$updated=$service->update('org-1',7,'corr-41','activation-41',[
    'channel_modes'=>['email'=>'auto','linkedin'=>'blocked','phone'=>'approval_required'],
    'reason'=>'Pilot automatic email while keeping other channels constrained.',
]);
expectGrowthV0410($executions->locks===1,'Activation update must serialize on tenant capacity lock.');
expectGrowthV0410($service->modeFor('org-1','email')===EngagementActivationMode::Auto,'Email AUTO mode was not persisted.');
expectGrowthV0410($service->modeFor('org-1','linkedin')===EngagementActivationMode::Blocked,'LinkedIn block was not persisted.');
expectGrowthV0410(($updated['profile']['revision']??null)===1,'Activation profile revision must be append-only.');

$engine=new PolicyEngine(new ConditionEvaluator());
$policies=(new GrowthPolicyCatalog())->policies('org-1');
expectGrowthV0410($engine->evaluate('growth.send_message',['growth'=>['activation_mode'=>'auto']],$policies)->decision===PolicyDecision::Auto,'AUTO activation must resolve to Kernel AUTO.');
expectGrowthV0410($engine->evaluate('growth.send_message',['growth'=>['activation_mode'=>'approval_required']],$policies)->decision===PolicyDecision::ApprovalRequired,'Approval activation must require Kernel approval.');
expectGrowthV0410($engine->evaluate('growth.send_message',['growth'=>['activation_mode'=>'blocked']],$policies)->decision===PolicyDecision::Denied,'Blocked activation must resolve to Kernel DENIED.');
expectGrowthV0410(EngagementActivationMode::Auto->executionMode()==='AUTO','AUTO execution mode is wrong.');
expectGrowthV0410(!EngagementActivationMode::Blocked->canPropose(),'Blocked mode must reject new proposals.');

echo "Growth V0.41 Outreach Activation Policy contracts passed.\n";
