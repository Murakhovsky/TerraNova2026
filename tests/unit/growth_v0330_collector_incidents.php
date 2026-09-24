<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthSignalPollingIncidentRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthCollectorAlertSubscriptionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthCollectorIncidentAlertGatewayInterface;
use Domains\Growth\Domain\GrowthCollectorAlertSubscription;
use Domains\Growth\Application\Service\GrowthSignalPollingIncidentService;
use Domains\Growth\Automation\Event\GrowthEventType;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

function expectGrowthV0330(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$transactions=new class implements TransactionManagerInterface {
    private bool $active=false;
    public function transactional(callable $operation):mixed
    {
        $previous=$this->active;
        $this->active=true;
        try{return $operation();}finally{$this->active=$previous;}
    }
    public function isActive():bool{return $this->active;}
    public function afterCommit(callable $callback):void{$callback();}
};

$store=new class implements EventStoreInterface {
    /** @var list<DomainEvent> */
    public array $events=[];
    public function append(DomainEvent $event):void{$this->events[]=$event;}
    public function find(string $eventId):?DomainEvent
    {
        foreach($this->events as $event)if($event->id===$eventId)return $event;
        return null;
    }
    public function findByAggregate(string $organizationId,string $aggregateType,string $aggregateId,int $limit=100):array
    {
        return array_values(array_filter(
            $this->events,
            static fn(DomainEvent $event):bool=>
                $event->organizationId===$organizationId
                &&$event->aggregateType===$aggregateType
                &&$event->aggregateId===$aggregateId,
        ));
    }
};
$events=new EventBus($store,$transactions);

$audit=new class implements AuditRepositoryInterface {
    /** @var list<AuditEntry> */
    public array $entries=[];
    public function append(AuditEntry $entry):void{$this->entries[]=$entry;}
};

$repository=new class implements GrowthSignalPollingIncidentRepositoryInterface {
    /** @var array<string,array<string,mixed>> */
    public array $open=[];
    /** @var list<array<string,mixed>> */
    public array $resolved=[];

    public function openIncident(string $organizationId,string $collectorName):?array
    {
        return $this->open[$organizationId.':'.$collectorName]??null;
    }

    public function activeIncidents(string $organizationId):array
    {
        return array_values(array_filter(
            $this->open,
            static fn(array $row):bool=>($row['organization_id']??null)===$organizationId,
        ));
    }

    public function upsertOpen(
        string $organizationId,string $incidentId,string $collectorName,int $failureCount,string $errorSummary,
        DateTimeImmutable $openedAt,DateTimeImmutable $lastFailureAt,DateTimeImmutable $nextRetryAt,
    ):array {
        $key=$organizationId.':'.$collectorName;
        $existing=$this->open[$key]??null;
        $row=[
            'organization_id'=>$organizationId,
            'incident_id'=>$existing['incident_id']??$incidentId,
            'collector_name'=>$collectorName,
            'status'=>'open',
            'failure_count'=>$failureCount,
            'opened_at'=>$existing['opened_at']??$openedAt->format(DATE_ATOM),
            'last_failure_at'=>$lastFailureAt->format(DATE_ATOM),
            'next_retry_at'=>$nextRetryAt->format(DATE_ATOM),
            'error_summary'=>$errorSummary,
            'resolved_at'=>null,
        ];
        return $this->open[$key]=$row;
    }

    public function resolveOpen(string $organizationId,string $collectorName,DateTimeImmutable $resolvedAt):?array
    {
        $key=$organizationId.':'.$collectorName;
        $row=$this->open[$key]??null;
        if($row===null)return null;
        unset($this->open[$key]);
        $row['status']='resolved';
        $row['resolved_at']=$resolvedAt->format(DATE_ATOM);
        $row['next_retry_at']=null;
        $this->resolved[]=$row;
        return $row;
    }
};

$alertSubscriptions=new class implements GrowthCollectorAlertSubscriptionRepositoryInterface {
    public function create(GrowthCollectorAlertSubscription $subscription,int $actorId):void{}
    public function lock(string $organizationId,string $subscriptionId):GrowthCollectorAlertSubscription{throw new InvalidArgumentException('unused');}
    public function update(GrowthCollectorAlertSubscription $subscription,int $actorId):void{}
    public function view(string $organizationId,string $subscriptionId):?array{return null;}
    public function findByEmail(string $organizationId,string $recipientEmail):?array{return null;}
    public function listAll(string $organizationId,int $limit=100):array{return [];}
    public function listEnabled(string $organizationId,int $limit=100):array{
        return [[
            'subscription_id'=>'sub-1',
            'recipient_email'=>'ops@example.test',
            'recipient_name'=>'Ops',
            'locale'=>'en',
            'enabled'=>true,
        ]];
    }
};
$alerts=new class implements GrowthCollectorIncidentAlertGatewayInterface {
    /** @var list<array<string,mixed>> */
    public array $queued=[];
    public function queue(string $organizationId,array $subscription,array $incident,string $transition,string $correlationId):void
    {
        $this->queued[]=['organization_id'=>$organizationId,'incident'=>$incident,'transition'=>$transition];
    }
};

$service=new GrowthSignalPollingIncidentService($repository,$events,$transactions,$audit,$alertSubscriptions,$alerts,3);
$failedAt=new DateTimeImmutable('2026-09-24T08:00:00+00:00');

expectGrowthV0330(
    $service->recordFailure('org-1',42,'corr-1','rss_atom',2,'still flaky',$failedAt,$failedAt->modify('+30 minutes'))===null,
    'Growth collector incident must not open before configured threshold.'
);
expectGrowthV0330($repository->activeIncidents('org-1')===[],'Pre-threshold failure opened an incident.');

$opened=$service->recordFailure(
    'org-1',42,'corr-2','rss_atom',3,'provider unavailable',$failedAt,$failedAt->modify('+60 minutes'),
);
expectGrowthV0330(($opened['status']??null)==='open','Third consecutive failure must open incident.');
expectGrowthV0330(($opened['failure_count']??null)===3,'Opened incident lost failure count.');
expectGrowthV0330(count($store->events)===1,'Opening incident must emit exactly one event.');
expectGrowthV0330($store->events[0]->type===GrowthEventType::COLLECTOR_INCIDENT_OPENED,'Wrong incident opened event.');

$updated=$service->recordFailure(
    'org-1',42,'corr-3','rss_atom',4,'provider still unavailable',$failedAt->modify('+60 minutes'),$failedAt->modify('+120 minutes'),
);
expectGrowthV0330(($updated['incident_id']??null)===($opened['incident_id']??null),'Consecutive failures must update the same open incident.');
expectGrowthV0330(($updated['failure_count']??null)===4,'Open incident failure count was not updated.');
expectGrowthV0330(count($store->events)===1,'Updating an open incident must not emit duplicate opened events.');

$resolved=$service->recordRecovery('org-1',42,'corr-4','rss_atom',$failedAt->modify('+121 minutes'));
expectGrowthV0330(($resolved['status']??null)==='resolved','Recovery must resolve open collector incident.');
expectGrowthV0330($repository->activeIncidents('org-1')===[],'Resolved collector incident remained active.');
expectGrowthV0330(count($store->events)===2,'Recovery must emit incident resolved event.');
expectGrowthV0330($store->events[1]->type===GrowthEventType::COLLECTOR_INCIDENT_RESOLVED,'Wrong incident resolved event.');
expectGrowthV0330(count($audit->entries)===2,'Incident open and recovery must be audited.');
expectGrowthV0330(count($alerts->queued)===2,'Incident open and recovery must queue operator alerts after commit.');
expectGrowthV0330($alerts->queued[0]['transition']==='opened'&&$alerts->queued[1]['transition']==='resolved','Incident alert transitions are wrong.');

echo "Growth V0.33 Collector Incidents & Recovery contracts passed.\n";
