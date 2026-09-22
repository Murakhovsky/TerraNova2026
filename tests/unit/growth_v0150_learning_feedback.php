<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Growth\Application\Contract\GrowthHandoffRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthLearningRepositoryInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Automation\Event\GrowthOutcomeFeedbackConsumer;
use Domains\Growth\Domain\GrowthOutcomeObservation;
use Domains\Growth\Domain\GrowthOutcomeType;
use Domains\Sales\Automation\Event\LeadChanged;
use Domains\Sales\Automation\Event\SalesEventType;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;
use Kernel\Transaction\Contract\TransactionManagerInterface;

function expectGrowthV0150(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$transactions=new class implements TransactionManagerInterface {
    private int $depth=0;
    public function transactional(callable $operation):mixed
    {
        $this->depth++;
        try{return $operation();}finally{$this->depth--;}
    }
    public function isActive():bool{return $this->depth>0;}
    public function afterCommit(callable $callback):void{$callback();}
};

$eventStore=new class implements EventStoreInterface {
    /** @var array<string,DomainEvent> */
    public array $events=[];
    public function append(DomainEvent $event):void{$this->events[$event->id]=$event;}
    public function find(string $eventId):?DomainEvent{return $this->events[$eventId]??null;}
    public function findByAggregate(string $organizationId,string $aggregateType,string $aggregateId,int $limit=100):array
    {
        return array_values(array_filter($this->events,static fn(DomainEvent $event):bool=>
            $event->organizationId===$organizationId&&$event->aggregateType===$aggregateType&&$event->aggregateId===$aggregateId
        ));
    }
};
$eventBus=new EventBus($eventStore,$transactions);

$audit=new class implements AuditRepositoryInterface {
    /** @var list<AuditEntry> */
    public array $entries=[];
    public function append(AuditEntry $entry):void{$this->entries[]=$entry;}
};

$states=new class implements ModuleStateRepositoryInterface {
    /** @var array<string,bool> */
    public array $values=[];
    public function enabledOverride(string $organizationId,string $moduleId):?bool{return $this->values[$organizationId.':'.$moduleId]??null;}
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void{$this->values[$organizationId.':'.$moduleId]=$enabled;}
};
$modules=new ActiveModuleResolver(new ModuleCatalog([
    new ModuleManifest('growth','Growth','0.15.0',enabledByDefault:true,schemaVersion:'0.15.0'),
]),$states);

$handoffs=new class implements GrowthHandoffRepositoryInterface {
    /** @var array<string,string> */
    public array $targets=['org-1:sales:sales_lead:101'=>'CAND-1'];
    public function createAttempt(string $organizationId,string $attemptId,\Domains\Growth\Application\DTO\OpportunityHandoff $handoff,string $payloadFingerprint,int $actorId):void{}
    public function viewAttempt(string $organizationId,string $attemptId):?array{return null;}
    public function hasRunningAttempt(string $organizationId,string $candidateId):bool{return false;}
    public function acceptAttempt(string $organizationId,string $attemptId,string $referenceType,string $referenceId,string $reason):void{}
    public function rejectAttempt(string $organizationId,string $attemptId,string $reason):void{}
    public function failAttempt(string $organizationId,string $attemptId,string $errorSummary):void{}
    public function latestAttempt(string $organizationId,string $candidateId):?array{return null;}
    public function candidateByTargetReference(string $organizationId,string $targetDomain,string $referenceType,string $referenceId):?string
    {
        return $this->targets[$organizationId.':'.$targetDomain.':'.$referenceType.':'.$referenceId]??null;
    }
};

$learning=new class implements GrowthLearningRepositoryInterface {
    /** @var array<string,string> */
    public array $bindings=[];
    /** @var array<string,GrowthOutcomeObservation> */
    public array $outcomes=[];

    public function bindExternalSubject(string $organizationId,string $candidateId,string $sourceDomain,string $referenceType,string $referenceId,string $sourceEventId):void
    {
        $key=$organizationId.':'.$sourceDomain.':'.$referenceType.':'.$referenceId;
        if(isset($this->bindings[$key])&&$this->bindings[$key]!==$candidateId)throw new RuntimeException('binding_conflict');
        $this->bindings[$key]=$candidateId;
    }
    public function candidateByExternalSubject(string $organizationId,string $sourceDomain,string $referenceType,string $referenceId):?string
    {
        return $this->bindings[$organizationId.':'.$sourceDomain.':'.$referenceType.':'.$referenceId]??null;
    }
    public function recordOutcome(GrowthOutcomeObservation $outcome):void{$this->outcomes[$outcome->sourceEventId]=$outcome;}
    public function outcomesForCandidate(string $organizationId,string $candidateId,int $limit=100):array
    {
        return array_values(array_map(static fn(GrowthOutcomeObservation $o):array=>$o->toArray(),array_filter(
            $this->outcomes,static fn(GrowthOutcomeObservation $o):bool=>$o->candidateId===$candidateId
        )));
    }
    public function outcomeSummary(string $organizationId,string $candidateId):array{return [];}
};

$consumer=new GrowthOutcomeFeedbackConsumer($handoffs,$learning,$modules,$transactions,$eventBus,$audit);
expectGrowthV0150($consumer->consumerName()==='growth.outcome-feedback.v1','Growth learning consumer name drifted.');

$metadata=new EventMetadata('corr-1',null,'USER','7');
$leadChanged=LeadChanged::create('sales-event-1','org-1','101',[
    'client_case_id'=>['from'=>null,'to'=>501],
    'status'=>['from'=>'new','to'=>'qualified'],
],$metadata);
$consumer->handle($leadChanged);

expectGrowthV0150(($learning->bindings['org-1:sales:sales_lead:101']??null)==='CAND-1','Lead binding was not materialized from accepted handoff.');
expectGrowthV0150(($learning->bindings['org-1:sales:sales_deal:501']??null)==='CAND-1','Lead conversion did not bind Sales deal to Growth Candidate.');
expectGrowthV0150(isset($learning->outcomes['sales-event-1']),'Qualified outcome was not recorded.');
expectGrowthV0150($learning->outcomes['sales-event-1']->outcomeType===GrowthOutcomeType::Qualified,'LeadChanged was not normalized to qualified outcome.');

$won=new DomainEvent(
    'sales-event-2','org-1',SalesEventType::DEAL_WON,'deal','501',
    ['deal_value'=>125000.0,'currency'=>'USD'],
    new EventMetadata('corr-2',null,'USER','7'),new DateTimeImmutable('2026-09-23T00:10:00+00:00'),
);
$consumer->handle($won);
expectGrowthV0150(isset($learning->outcomes['sales-event-2']),'Won outcome was not recorded.');
expectGrowthV0150($learning->outcomes['sales-event-2']->outcomeType===GrowthOutcomeType::Won,'Deal won event was not normalized.');
expectGrowthV0150($learning->outcomes['sales-event-2']->economicValue===125000.0,'Deal won economic value was lost.');
expectGrowthV0150($learning->outcomes['sales-event-2']->currency==='USD','Deal won currency was lost.');

$growthEvents=array_values(array_filter($eventStore->events,static fn(DomainEvent $event):bool=>str_starts_with($event->type,'growth.')));
$types=array_map(static fn(DomainEvent $event):string=>$event->type,$growthEvents);
expectGrowthV0150(in_array(GrowthEventType::LEARNING_BINDING_CREATED,$types,true),'Growth binding event was not published.');
expectGrowthV0150(count(array_filter($types,static fn(string $type):bool=>$type===GrowthEventType::OUTCOME_RECORDED))===2,'Growth outcome events were not published exactly twice.');
expectGrowthV0150(count($audit->entries)===2,'Growth learning audit must record normalized outcomes.');

$unsupported=new DomainEvent(
    'sales-event-3','org-1',SalesEventType::MESSAGE_RECEIVED,'thread','77',[],
    new EventMetadata('corr-3',null,'SYSTEM','fixture'),new DateTimeImmutable(),
);
$consumer->handle($unsupported);
expectGrowthV0150(!isset($learning->outcomes['sales-event-3']),'Unsupported Sales aggregate type must not be guessed as a lead.');

$states->setEnabled('org-1','growth',false);
$disabled=new DomainEvent(
    'sales-event-4','org-1',SalesEventType::DEAL_LOST,'deal','501',[],
    new EventMetadata('corr-4',null,'USER','7'),new DateTimeImmutable(),
);
$consumer->handle($disabled);
expectGrowthV0150(!isset($learning->outcomes['sales-event-4']),'Disabled Growth module must not consume Sales feedback.');

echo "Growth V0.15 Learning Feedback contracts passed.\n";
