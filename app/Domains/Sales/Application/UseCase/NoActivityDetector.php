<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\SalesAttentionRepositoryInterface;
use Domains\Sales\Automation\Event\SalesEventType;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class NoActivityDetector
{
    public function __construct(private SalesAttentionRepositoryInterface $repository,private EventBus $events,private TransactionManagerInterface $transactions) {}
    public function detect(string $organizationId,\DateTimeImmutable $now,int $hours=48,int $limit=100):int
    {
        $cutoff=$now->modify('-'.max(1,$hours).' hours');$count=0;
        foreach($this->repository->inactiveDeals($organizationId,$cutoff,$limit) as $deal){$this->transactions->transactional(function()use($organizationId,$now,$hours,$deal,&$count){$window=$deal['id'].':'.$hours.':'.($deal['last_activity_at']??'never');$eventId=bin2hex(random_bytes(16));if(!$this->repository->claimSignal($organizationId,'no_activity',$window,$eventId))return;$this->events->publish(new DomainEvent($eventId,$organizationId,SalesEventType::NO_ACTIVITY_DETECTED,'deal',(string)$deal['id'],['hours'=>$hours,'last_activity_at'=>$deal['last_activity_at']],new EventMetadata($eventId,null,'SYSTEM','sales-no-activity-detector'),$now));$count++;});}
        return $count;
    }
}
