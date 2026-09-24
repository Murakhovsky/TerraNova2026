<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Growth\Application\Contract\GrowthEngagementLimitProfileRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Service\GrowthEngagementLimitService;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

function expectGrowthV0380(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$profiles=new class implements GrowthEngagementLimitProfileRepositoryInterface {
    public array $rows=[];
    public function latest(string $organizationId):?array{
        $rows=$this->rows[$organizationId]??[];
        return $rows===[]?null:$rows[array_key_last($rows)];
    }
    public function append(array $profile):void{$this->rows[$profile['organization_id']][]=$profile;}
};
$receipts=new class implements GrowthMutationReceiptInterface {
    public array $claims=[];
    public function claim(string $organizationId,string $operation,string $idempotencyKey,string $fingerprint):bool{
        $key=$organizationId.':'.$operation.':'.$idempotencyKey;
        if(isset($this->claims[$key])){
            if($this->claims[$key]!==$fingerprint)throw new InvalidArgumentException('idempotency conflict');
            return false;
        }
        $this->claims[$key]=$fingerprint;
        return true;
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
    public array $items=[];
    public function append(DomainEvent $event):void{$this->items[]=$event;}
    public function find(string $eventId):?DomainEvent{return null;}
    public function findByAggregate(string $organizationId,string $aggregateType,string $aggregateId,int $limit=100):array{return [];}
};
$audit=new class implements AuditRepositoryInterface {
    public array $entries=[];
    public function append(AuditEntry $entry):void{$this->entries[]=$entry;}
};

$service=new GrowthEngagementLimitService(
    $profiles,$receipts,$transactions,new EventBus($events,$transactions),$audit,50,24,
);

$default=$service->view('org-1');
expectGrowthV0380(($default['source']??null)==='deployment_default','Growth must expose deployment defaults before tenant override.');
expectGrowthV0380(($default['effective']['daily_limit']??null)===50,'Growth default daily limit is wrong.');

$updated=$service->update('org-1',7,'corr-1','limits-1',[
    'daily_limit'=>25,
    'contact_cooldown_hours'=>48,
    'reason'=>'Smaller outbound team this month.',
]);
expectGrowthV0380(($updated['source']??null)==='tenant_profile','Growth tenant limit override was not activated.');
expectGrowthV0380(($updated['profile']['revision']??null)===1,'First Growth tenant limit profile must be revision 1.');
expectGrowthV0380(($updated['effective']['daily_limit']??null)===25,'Growth tenant daily limit was not applied.');
expectGrowthV0380($service->policyFor('org-1')->contactCooldownHours===48,'Growth execution policy must resolve tenant cooldown.');
expectGrowthV0380(count($events->items)===1&&count($audit->entries)===1,'Growth tenant limit update must emit Event and Audit.');

$replayed=$service->update('org-1',7,'corr-2','limits-1',[
    'daily_limit'=>25,
    'contact_cooldown_hours'=>48,
    'reason'=>'Smaller outbound team this month.',
]);
expectGrowthV0380(($replayed['replayed']??false)===true,'Growth tenant limit update must replay idempotently.');
expectGrowthV0380(count($events->items)===1,'Replayed Growth tenant limit update must not emit duplicate Event.');

$updated2=$service->update('org-1',7,'corr-3','limits-2',[
    'daily_limit'=>'30',
    'contact_cooldown_hours'=>'36',
    'reason'=>'Team capacity increased.',
]);
expectGrowthV0380(($updated2['profile']['revision']??null)===2,'Growth tenant limit revisions must be append-only.');
expectGrowthV0380($service->policyFor('org-1')->dailyLimit===30,'Latest Growth tenant limit revision must become effective.');

$noOp=false;
try{
    $service->update('org-1',7,'corr-4','limits-3',[
        'daily_limit'=>30,
        'contact_cooldown_hours'=>36,
        'reason'=>'No material change.',
    ]);
}catch(InvalidArgumentException){$noOp=true;}
expectGrowthV0380($noOp,'Growth tenant limit service must reject no-op revisions.');

echo "Growth V0.38 Tenant Outreach Limits contracts passed.\n";
