<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthEngagementLimitProfileRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Service\GrowthEngagementLimitService;
use Domains\Growth\Domain\EngagementExecutionLimitPolicy;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

function expectGrowthV0390(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$now=new DateTimeImmutable('2026-09-24T12:00:00+00:00');
$policy=new EngagementExecutionLimitPolicy(10,24,[
    'email'=>4,
    'linkedin'=>2,
    'phone'=>1,
]);

$emailOk=$policy->evaluate(3,null,$now,'email',3);
expectGrowthV0390($emailOk['allowed']===true,'Email quota below limit must allow outreach.');

$linkedinBlocked=$policy->evaluate(3,null,$now,'linkedin',2);
expectGrowthV0390(
    $linkedinBlocked['allowed']===false
    &&$linkedinBlocked['code']==='pre_handoff_channel_daily_limit_reached'
    &&$linkedinBlocked['scope']==='channel'
    &&$linkedinBlocked['channel']==='linkedin'
    &&$linkedinBlocked['used']===2
    &&$linkedinBlocked['limit']===2,
    'LinkedIn channel quota was not enforced with explainable usage.',
);

$globalBlocked=$policy->evaluate(10,null,$now,'email',1);
expectGrowthV0390(
    $globalBlocked['code']==='pre_handoff_daily_limit_reached'&&$globalBlocked['scope']==='organization',
    'Organization-wide limit must remain the hard ceiling above channel quotas.',
);

$disabled=new EngagementExecutionLimitPolicy(10,24,['email'=>10,'linkedin'=>10,'phone'=>0]);
$phoneBlocked=$disabled->evaluate(0,null,$now,'phone',0);
expectGrowthV0390(
    $phoneBlocked['allowed']===false&&$phoneBlocked['code']==='pre_handoff_channel_daily_limit_reached',
    'Zero channel quota must disable new execution on that channel.',
);

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
    public function transactional(callable $operation):mixed{return $operation();}
    public function isActive():bool{return false;}
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
    $profiles,$receipts,$transactions,new EventBus($events,$transactions),$audit,
    50,24,40,20,10,
);
$defaults=$service->view('org-1');
expectGrowthV0390(
    ($defaults['effective']['channel_daily_limits']??null)===['email'=>40,'linkedin'=>20,'phone'=>10],
    'Deployment channel quota defaults are not exposed.',
);

$updated=$service->update('org-1',7,'corr-39','limits-39',[
    'daily_limit'=>30,
    'contact_cooldown_hours'=>36,
    'channel_daily_limits'=>['email'=>25,'linkedin'=>8,'phone'=>3],
    'reason'=>'Align quotas with staffed channel capacity.',
]);
expectGrowthV0390(
    ($updated['effective']['channel_daily_limits']??null)===['email'=>25,'linkedin'=>8,'phone'=>3],
    'Tenant channel quota profile was not applied.',
);
expectGrowthV0390($service->policyFor('org-1')->channelDailyLimit('linkedin')===8,'Effective tenant LinkedIn quota is wrong.');
expectGrowthV0390(($updated['profile']['email_daily_limit']??null)===25,'Email quota was not persisted in the append-only profile.');

$legacy=$service->update('org-1',7,'corr-legacy','limits-legacy',[
    'daily_limit'=>6,
    'contact_cooldown_hours'=>48,
    'reason'=>'Legacy client omitted channel quotas.',
]);
expectGrowthV0390(
    ($legacy['effective']['channel_daily_limits']??null)===['email'=>6,'linkedin'=>6,'phone'=>3],
    'Legacy V0.38 update must inherit channel quotas and clamp them to a reduced organization cap.',
);

echo "Growth V0.39 Channel Outreach Quotas contracts passed.\n";
