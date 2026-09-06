<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\SalesAttentionRepositoryInterface;
use Domains\Sales\Automation\Event\SalesEventType;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class DetectMissedFollowups
{
    public function __construct(private SalesAttentionRepositoryInterface $repository,private EventBus $events,private TransactionManagerInterface $transactions) {}
    public function detect(string $organizationId,\DateTimeImmutable $now,int $limit=100):int{$count=0;foreach($this->repository->missedFollowups($organizationId,$now,$limit) as $followup){$this->transactions->transactional(function()use($organizationId,$now,$followup,&$count){$key=$followup['id'].':'.$followup['due_at'];$eventId=bin2hex(random_bytes(16));if(!$this->repository->claimSignal($organizationId,'followup_missed',$key,$eventId))return;$this->events->publish(new DomainEvent($eventId,$organizationId,SalesEventType::FOLLOWUP_MISSED,'deal',(string)$followup['client_case_id'],['followup_id'=>(string)$followup['id'],'due_at'=>$followup['due_at']],new EventMetadata($eventId,null,'SYSTEM','sales-followup-detector'),$now));$count++;});}return $count;}
}
