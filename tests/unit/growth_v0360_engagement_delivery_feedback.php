<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Growth\Application\Contract\GrowthEngagementDeliveryRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionRepositoryInterface;
use Domains\Growth\Application\Service\GrowthEngagementDeliveryService;
use Domains\Growth\Domain\EngagementChannel;
use Domains\Growth\Domain\EngagementDeliveryStatus;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

function expectGrowthV0360(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

expectGrowthV0360(EngagementDeliveryStatus::Delivered->supportsChannel(EngagementChannel::LinkedIn),'LinkedIn delivered status must be valid.');
expectGrowthV0360(!EngagementDeliveryStatus::Completed->supportsChannel(EngagementChannel::LinkedIn),'LinkedIn must not accept call-completed status.');
expectGrowthV0360(EngagementDeliveryStatus::Completed->supportsChannel(EngagementChannel::Phone),'Phone completed status must be valid.');
expectGrowthV0360(EngagementDeliveryStatus::NoAnswer->isTerminal(),'No-answer must be terminal.');

$executions=new class implements GrowthEngagementExecutionRepositoryInterface {
    public array $rows=[
        'ACT-LI'=>[
            'organization_id'=>'org-1','execution_id'=>'EXE-LI','candidate_id'=>'CAND-LI','recommendation_id'=>'REC-LI',
            'target_domain'=>'growth','target_reference_type'=>'growth_contact','target_reference_id'=>'GCNT-1',
            'action_id'=>'ACT-LI','action_type'=>'growth.send_linkedin','channel'=>'linkedin','payload_fingerprint'=>'x',
        ],
        'ACT-PHONE'=>[
            'organization_id'=>'org-1','execution_id'=>'EXE-PH','candidate_id'=>'CAND-PH','recommendation_id'=>'REC-PH',
            'target_domain'=>'growth','target_reference_type'=>'growth_contact','target_reference_id'=>'GCNT-2',
            'action_id'=>'ACT-PHONE','action_type'=>'growth.place_call','channel'=>'phone','payload_fingerprint'=>'y',
        ],
        'ACT-SALES'=>[
            'organization_id'=>'org-1','execution_id'=>'EXE-SALES','candidate_id'=>'CAND-S','recommendation_id'=>'REC-S',
            'target_domain'=>'sales','target_reference_type'=>'sales_deal','target_reference_id'=>'42',
            'action_id'=>'ACT-SALES','action_type'=>'sales.send_message','channel'=>'linkedin','payload_fingerprint'=>'z',
        ],
    ];
    public function byRecommendation(string $organizationId,string $recommendationId):?array{
        foreach($this->rows as $row)if($row['recommendation_id']===$recommendationId)return $row;
        return null;
    }
    public function byActionId(string $organizationId,string $actionId):?array{return $this->rows[$actionId]??null;}
    public function createOrVerify(string $organizationId,string $executionId,string $candidateId,string $recommendationId,string $targetDomain,string $targetReferenceType,string $targetReferenceId,string $actionId,string $actionType,string $channel,string $payloadFingerprint,int $actorId):void{}
    public function latestForCandidate(string $organizationId,string $candidateId):?array{return null;}
    public function countPreHandoffSince(string $organizationId,string $since):int{return 0;}
    public function latestPreHandoffForTarget(string $organizationId,string $targetReferenceId):?array{return null;}
};

$deliveries=new class implements GrowthEngagementDeliveryRepositoryInterface {
    public array $rows=[];
    public function recordOrVerify(array $observation):array{
        $key=$observation['source_event_id'];
        if(isset($this->rows[$key])){
            foreach(['execution_id','action_id','channel','status'] as $field){
                if(($this->rows[$key][$field]??null)!==($observation[$field]??null))throw new InvalidArgumentException('Growth delivery source event conflicts with an existing observation.');
            }
            return $this->rows[$key]+['replayed'=>true];
        }
        return $this->rows[$key]=$observation+['replayed'=>false];
    }
    public function latestForExecution(string $organizationId,string $executionId):?array{
        $rows=$this->forExecution($organizationId,$executionId,1);
        return $rows[0]??null;
    }
    public function forExecution(string $organizationId,string $executionId,int $limit=20):array{
        $rows=array_values(array_filter($this->rows,static fn(array $row):bool=>$row['execution_id']===$executionId));
        return array_slice(array_reverse($rows),0,$limit);
    }
};

$transactions=new class implements TransactionManagerInterface {
    private bool $active=false;
    public function transactional(callable $operation):mixed{
        $previous=$this->active;$this->active=true;
        try{return $operation();}finally{$this->active=$previous;}
    }
    public function isActive():bool{return $this->active;}
    public function afterCommit(callable $callback):void{$callback();}
};
$events=new class implements EventStoreInterface {
    public array $events=[];
    public function append(DomainEvent $event):void{$this->events[]=$event;}
    public function find(string $eventId):?DomainEvent{return null;}
    public function findByAggregate(string $organizationId,string $aggregateType,string $aggregateId,int $limit=100):array{return [];}
};
$audit=new class implements AuditRepositoryInterface {
    public array $entries=[];
    public function append(AuditEntry $entry):void{$this->entries[]=$entry;}
};

$service=new GrowthEngagementDeliveryService($executions,$deliveries,$transactions,new EventBus($events,$transactions),$audit);
$result=$service->recordExternalStatus(
    'org-1','corr-1','provider-event-1','ACT-LI','linkedin','delivered',
    '2026-09-24T11:00:00+00:00','linkedin-msg-77',null,null,
);
expectGrowthV0360(($result['status']??null)==='delivered','LinkedIn delivery status was not recorded.');
expectGrowthV0360(($result['terminal']??null)===true,'Delivered status must persist terminal=true.');
expectGrowthV0360(count($events->events)===1,'New delivery observation must publish one Growth event.');
expectGrowthV0360(count($audit->entries)===1,'New delivery observation must append one audit entry.');

$replayed=$service->recordExternalStatus(
    'org-1','corr-2','provider-event-1','ACT-LI','linkedin','delivered',
    '2026-09-24T11:00:00+00:00','linkedin-msg-77',null,null,
);
expectGrowthV0360(($replayed['replayed']??false)===true,'Repeated provider event must replay idempotently.');
expectGrowthV0360(count($events->events)===1,'Replayed provider event must not publish a duplicate event.');

$failed=false;
try{
    $service->recordExternalStatus(
        'org-1','corr-3','provider-event-2','ACT-LI','linkedin','completed',
        '2026-09-24T11:05:00+00:00',null,null,null,
    );
}catch(InvalidArgumentException){$failed=true;}
expectGrowthV0360($failed,'LinkedIn must reject phone-only delivery statuses.');

$failed=false;
try{
    $service->recordExternalStatus(
        'org-1','corr-4','provider-event-3','ACT-SALES','linkedin','sent',
        '2026-09-24T11:10:00+00:00',null,null,null,
    );
}catch(InvalidArgumentException){$failed=true;}
expectGrowthV0360($failed,'Growth delivery callback must reject post-handoff Sales executions.');

$phone=$service->recordExternalStatus(
    'org-1','corr-5','provider-event-4','ACT-PHONE','phone','no_answer',
    '2026-09-24T11:15:00+00:00','call-42','no_answer','Recipient did not answer.',
);
expectGrowthV0360(($phone['status']??null)==='no_answer'&&($phone['terminal']??null)===true,'Phone no-answer delivery observation failed.');

echo "Growth V0.36 Engagement Delivery Feedback contracts passed.\n";
