<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Growth\Application\Contract\GrowthHandoffRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthLearningRepositoryInterface;
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

function expectGrowthV0490(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$transactions=new class implements TransactionManagerInterface{
    private int $depth=0;
    public function transactional(callable $operation):mixed{$this->depth++;try{return $operation();}finally{$this->depth--;}}
    public function isActive():bool{return $this->depth>0;}
    public function afterCommit(callable $callback):void{$callback();}
};
$store=new class implements EventStoreInterface{
    public array $events=[];
    public function append(DomainEvent $event):void{$this->events[$event->id]=$event;}
    public function find(string $eventId):?DomainEvent{return $this->events[$eventId]??null;}
    public function findByAggregate(string $organizationId,string $aggregateType,string $aggregateId,int $limit=100):array{return [];}
};
$events=new EventBus($store,$transactions);
$audit=new class implements AuditRepositoryInterface{
    public array $entries=[];
    public function append(AuditEntry $entry):void{$this->entries[]=$entry;}
};
$states=new class implements ModuleStateRepositoryInterface{
    public function enabledOverride(string $organizationId,string $moduleId):?bool{return null;}
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void{}
};
$modules=new ActiveModuleResolver(new ModuleCatalog([
    new ModuleManifest('growth','Growth','0.49.0',enabledByDefault:true,schemaVersion:'0.49.0'),
]),$states);

$handoffs=new class implements GrowthHandoffRepositoryInterface{
    public function createAttempt(string $organizationId,string $attemptId,\Domains\Growth\Application\DTO\OpportunityHandoff $handoff,string $payloadFingerprint,int $actorId):void{}
    public function viewAttempt(string $organizationId,string $attemptId):?array{return null;}
    public function hasRunningAttempt(string $organizationId,string $candidateId):bool{return false;}
    public function acceptAttempt(string $organizationId,string $attemptId,string $referenceType,string $referenceId,string $reason):void{}
    public function rejectAttempt(string $organizationId,string $attemptId,string $reason):void{}
    public function failAttempt(string $organizationId,string $attemptId,string $errorSummary):void{}
    public function latestAttempt(string $organizationId,string $candidateId):?array{return null;}
    public function candidateByTargetReference(string $organizationId,string $targetDomain,string $referenceType,string $referenceId):?string{return null;}
};
$learning=new class implements GrowthLearningRepositoryInterface{
    public array $bindings=[];
    public array $outcomes=[];
    public function bindExternalSubject(string $organizationId,string $candidateId,string $sourceDomain,string $referenceType,string $referenceId,string $sourceEventId):void{
        $key=$organizationId.':'.$sourceDomain.':'.$referenceType.':'.$referenceId;
        if(isset($this->bindings[$key])&&$this->bindings[$key]!==$candidateId)throw new RuntimeException('binding conflict');
        $this->bindings[$key]=$candidateId;
    }
    public function candidateByExternalSubject(string $organizationId,string $sourceDomain,string $referenceType,string $referenceId):?string{
        return $this->bindings[$organizationId.':'.$sourceDomain.':'.$referenceType.':'.$referenceId]??null;
    }
    public function externalSubjectsForCandidate(string $organizationId,string $candidateId,string $sourceDomain,string $referenceType):array{return [];}
    public function recordOutcome(GrowthOutcomeObservation $outcome):void{$this->outcomes[$outcome->sourceEventId]=$outcome;}
    public function outcomesForCandidate(string $organizationId,string $candidateId,int $limit=100):array{return [];}
    public function outcomeSummary(string $organizationId,string $candidateId):array{return [];}
};

$learning->bindExternalSubject('org-1','CAND-COS-1','sales','sales_lead','901','GCR-ROUTE-1');
$consumer=new GrowthOutcomeFeedbackConsumer($handoffs,$learning,$modules,$transactions,$events,$audit);

$qualified=LeadChanged::create('sales-cos-1','org-1','901',[
    'status'=>['from'=>'new','to'=>'qualified'],
    'client_case_id'=>['from'=>null,'to'=>9901],
],new EventMetadata('corr-cos-1',null,'SYSTEM','fixture'));
$consumer->handle($qualified);

expectGrowthV0490(isset($learning->outcomes['sales-cos-1']),'Conversation-routed Sales lead did not return a Growth outcome.');
expectGrowthV0490($learning->outcomes['sales-cos-1']->candidateId==='CAND-COS-1','Sales outcome lost its Growth Candidate binding.');
expectGrowthV0490($learning->outcomes['sales-cos-1']->outcomeType===GrowthOutcomeType::Qualified,'Sales lead qualification did not normalize to qualified.');
expectGrowthV0490(($learning->bindings['org-1:sales:sales_deal:9901']??null)==='CAND-COS-1','Conversation-routed lead conversion did not bind the Sales deal.');

$won=new DomainEvent(
    'sales-cos-2','org-1',SalesEventType::DEAL_WON,'deal','9901',
    ['deal_value'=>24000.0,'currency'=>'USD'],
    new EventMetadata('corr-cos-2',null,'SYSTEM','fixture'),new DateTimeImmutable('2026-09-26T18:00:00+00:00')
);
$consumer->handle($won);
expectGrowthV0490(isset($learning->outcomes['sales-cos-2']),'Won Sales deal did not close the Growth learning loop.');
expectGrowthV0490($learning->outcomes['sales-cos-2']->outcomeType===GrowthOutcomeType::Won,'Won Sales deal outcome type drifted.');
expectGrowthV0490($learning->outcomes['sales-cos-2']->economicValue===24000.0,'Won value was not preserved in Growth learning.');
expectGrowthV0490($learning->outcomes['sales-cos-2']->currency==='USD','Won value currency was not preserved in Growth learning.');

echo "Growth V0.49 COS-for-COS closed-loop feedback contracts passed.\n";
