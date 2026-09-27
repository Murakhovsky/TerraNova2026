<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthCollectorAlertSubscriptionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthCollectorIncidentAlertGatewayInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Service\GrowthCollectorAlertService;
use Domains\Growth\Domain\GrowthCollectorAlertSubscription;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

function expectGrowthV0340(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$transactions=new class implements TransactionManagerInterface {
    private bool $active=false;
    /** @var list<callable():void> */
    private array $after=[];
    public function transactional(callable $operation):mixed
    {
        $previous=$this->active;
        $this->active=true;
        try{
            $result=$operation();
            $callbacks=$this->after;
            $this->after=[];
            $this->active=$previous;
            foreach($callbacks as $callback)$callback();
            return $result;
        }catch(Throwable $error){
            $this->after=[];
            $this->active=$previous;
            throw $error;
        }
    }
    public function isActive():bool{return $this->active;}
    public function afterCommit(callable $callback):void
    {
        if($this->active)$this->after[]=$callback;else $callback();
    }
};

$eventStore=new class implements EventStoreInterface {
    /** @var list<DomainEvent> */
    public array $events=[];
    public function append(DomainEvent $event):void{$this->events[]=$event;}
    public function find(string $eventId):?DomainEvent{return null;}
    public function findByAggregate(string $organizationId,string $aggregateType,string $aggregateId,int $limit=100):array{return [];}
};
$events=new EventBus($eventStore,$transactions);
$audit=new class implements AuditRepositoryInterface {
    /** @var list<AuditEntry> */
    public array $entries=[];
    public function append(AuditEntry $entry):void{$this->entries[]=$entry;}
};
$receipts=new class implements GrowthMutationReceiptInterface {
    /** @var array<string,string> */
    private array $claims=[];
    public function claim(string $organizationId,string $operation,string $idempotencyKey,string $fingerprint):bool
    {
        $key=$organizationId.':'.$operation.':'.$idempotencyKey;
        if(isset($this->claims[$key])){
            if($this->claims[$key]!==$fingerprint)throw new InvalidArgumentException('fingerprint mismatch');
            return false;
        }
        $this->claims[$key]=$fingerprint;
        return true;
    }
};
$subscriptions=new class implements GrowthCollectorAlertSubscriptionRepositoryInterface {
    /** @var array<string,GrowthCollectorAlertSubscription> */
    public array $items=[];
    /** @var array<string,array<string,mixed>> */
    public array $rows=[];

    public function create(GrowthCollectorAlertSubscription $subscription,int $actorId):void
    {
        $this->items[$subscription->id]=$subscription;
        $this->rows[$subscription->id]=$this->normalize($subscription,$actorId);
    }
    public function lock(string $organizationId,string $subscriptionId):GrowthCollectorAlertSubscription
    {
        return $this->items[$subscriptionId]??throw new InvalidArgumentException('missing');
    }
    public function update(GrowthCollectorAlertSubscription $subscription,int $actorId):void
    {
        $this->items[$subscription->id]=$subscription;
        $this->rows[$subscription->id]=$this->normalize($subscription,$actorId);
    }
    public function view(string $organizationId,string $subscriptionId):?array{return $this->rows[$subscriptionId]??null;}
    public function findByEmail(string $organizationId,string $recipientEmail):?array
    {
        foreach($this->rows as $row)if($row['recipient_email']===$recipientEmail)return $row;
        return null;
    }
    public function listAll(string $organizationId,int $limit=100):array{return array_values($this->rows);}
    public function listEnabled(string $organizationId,int $limit=100):array
    {
        return array_values(array_filter($this->rows,static fn(array $row):bool=>$row['enabled']===true));
    }
    private function normalize(GrowthCollectorAlertSubscription $subscription,int $actorId):array
    {
        return [
            'organization_id'=>$subscription->organizationId->value(),
            'subscription_id'=>$subscription->id,
            'recipient_email'=>$subscription->recipientEmail,
            'recipient_name'=>$subscription->recipientName,
            'locale'=>$subscription->locale,
            'enabled'=>$subscription->enabled(),
            'updated_by'=>$actorId,
        ];
    }
};

$service=new GrowthCollectorAlertService($subscriptions,$receipts,$events,$transactions,$audit);
$created=$service->createSubscription('org-1',42,'corr-1','key-1',[
    'recipient_email'=>'OPS@Example.COM',
    'recipient_name'=>'Growth Ops',
    'locale'=>'en',
    'enabled'=>true,
]);
expectGrowthV0340(($created['recipient_email']??null)==='ops@example.com','Collector alert email must be normalized.');
expectGrowthV0340(($created['enabled']??null)===true,'Collector alert subscription must be enabled.');
expectGrowthV0340(count($eventStore->events)===1,'Collector alert subscription creation must emit event.');
expectGrowthV0340(count($audit->entries)===1,'Collector alert subscription creation must be audited.');

$duplicate=$service->createSubscription('org-1',42,'corr-2','key-2',[
    'recipient_email'=>'ops@example.com','recipient_name'=>'Other','locale'=>'en','enabled'=>true,
]);
expectGrowthV0340(($duplicate['existing']??false)===true,'Duplicate alert email must resolve existing tenant subscription.');

$id=(string)$created['subscription_id'];
$disabled=$service->setEnabled('org-1',42,'corr-3',$id,false,'key-3');
expectGrowthV0340(($disabled['enabled']??true)===false,'Collector alert subscription disable failed.');
expectGrowthV0340($subscriptions->listEnabled('org-1')===[],'Disabled alert subscription remained dispatchable.');

try{
    new GrowthCollectorAlertSubscription(
        'bad',OrganizationId::fromString('org-1'),'not-an-email',null,'en',true,
    );
    throw new RuntimeException('Invalid collector alert email was accepted.');
}catch(InvalidArgumentException){}

echo "Growth V0.34 Collector Incident Email Alerts contracts passed.\n";
