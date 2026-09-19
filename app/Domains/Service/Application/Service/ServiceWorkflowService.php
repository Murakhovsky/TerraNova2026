<?php
declare(strict_types=1);

namespace Domains\Service\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Service\Application\Contract\ServiceApplicationBoundary;
use Domains\Service\Application\Contract\ServiceMutationReceiptInterface;
use Domains\Service\Application\Contract\ServiceRepositoryInterface;
use Domains\Service\Automation\Event\ServiceEventType;
use Domains\Service\Domain\ServiceTicketLifecycle;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class ServiceWorkflowService implements ServiceApplicationBoundary
{
    public function __construct(
        private ServiceRepositoryInterface $service,
        private ServiceMutationReceiptInterface $receipts,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    public function createRequest(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $idempotencyKey,
        array $input,
    ): array {
        $subject=$this->required($input,'subject',220);
        $summary=trim((string)($input['summary']??$subject));
        if($summary==='')$summary=$subject;
        $summary=$this->bounded($summary,'summary',500);
        $requesterRef=$this->optional($input,'requester_ref',191);

        $caseId='SCASE-'.$this->stableId($organizationId.':case:'.$idempotencyKey);
        $requestId='SREQ-'.$this->stableId($organizationId.':request:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'subject'=>$subject,'summary'=>$summary,'requester_ref'=>$requesterRef,
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$subject,$summary,$requesterRef,
            $caseId,$requestId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'create_request',$idempotencyKey,$fingerprint)){
                return ($this->service->viewRequest($organizationId,$requestId)
                    ?? throw new InvalidArgumentException('Service request receipt exists but Request was not found.'))
                    + ['replayed'=>true];
            }

            $this->service->createRequestCase(
                [
                    'organization_id'=>$organizationId,'case_id'=>$caseId,'subject'=>$subject,
                    'status'=>'open','created_by'=>$actorId,'updated_by'=>$actorId,
                ],
                [
                    'organization_id'=>$organizationId,'request_id'=>$requestId,'case_id'=>$caseId,
                    'summary'=>$summary,'requester_ref'=>$requesterRef,'status'=>'open',
                    'created_by'=>$actorId,'updated_by'=>$actorId,
                ],
            );
            $this->publish(ServiceEventType::REQUEST_CREATED,$organizationId,'service_request',$requestId,[
                'case_id'=>$caseId,'summary'=>$summary,'requester_ref'=>$requesterRef,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'service.request.create','service_request',$requestId,$idempotencyKey,[
                'case_id'=>$caseId,
            ]);
            return $this->service->viewRequest($organizationId,$requestId)
                ?? throw new InvalidArgumentException('Created Service request could not be read back.');
        });
    }

    public function createTicket(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $requestId,
        string $idempotencyKey,
        array $input,
    ): array {
        $requestId=$this->bounded(trim($requestId),'requestId',80);
        $subject=$this->required($input,'subject',220);
        $priority=strtolower(trim((string)($input['priority']??'normal')));
        if(!in_array($priority,['low','normal','high','urgent'],true)){
            throw new InvalidArgumentException('Service ticket priority is invalid.');
        }
        $reference=trim((string)($input['reference']??''));
        if($reference==='')$reference='SRV-'.substr($this->stableId($organizationId.':reference:'.$idempotencyKey),0,12);
        $reference=$this->bounded($reference,'reference',80);

        $ticketId='STKT-'.$this->stableId($organizationId.':ticket:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'request_id'=>$requestId,'subject'=>$subject,'priority'=>$priority,'reference'=>$reference,
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$requestId,$idempotencyKey,$subject,$priority,$reference,
            $ticketId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'create_ticket',$idempotencyKey,$fingerprint)){
                return ($this->service->viewTicket($organizationId,$ticketId)
                    ?? throw new InvalidArgumentException('Service ticket receipt exists but Ticket was not found.'))
                    + ['replayed'=>true];
            }
            $request=$this->service->lockRequest($organizationId,$requestId);
            if((string)($request['status']??'')==='closed'){
                throw new InvalidArgumentException('Closed Service request cannot create Tickets.');
            }

            $this->service->createTicket([
                'organization_id'=>$organizationId,'ticket_id'=>$ticketId,'request_id'=>$requestId,
                'reference'=>$reference,'subject'=>$subject,'priority'=>$priority,'status'=>ServiceTicketLifecycle::OPEN,
                'escalation_level'=>0,'created_by'=>$actorId,'updated_by'=>$actorId,
            ]);
            $this->publish(ServiceEventType::TICKET_CREATED,$organizationId,'service_ticket',$ticketId,[
                'request_id'=>$requestId,'reference'=>$reference,'priority'=>$priority,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'service.ticket.create','service_ticket',$ticketId,$idempotencyKey,[
                'request_id'=>$requestId,'reference'=>$reference,
            ]);
            return $this->service->viewTicket($organizationId,$ticketId)
                ?? throw new InvalidArgumentException('Created Service ticket could not be read back.');
        });
    }

    public function assignTicket(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $ticketId,
        string $assigneeId,
        string $idempotencyKey,
    ): array {
        $ticketId=$this->bounded(trim($ticketId),'ticketId',80);
        $assigneeId=$this->bounded(trim($assigneeId),'assigneeId',191);
        $assignmentId='SASN-'.$this->stableId($organizationId.':assignment:'.$idempotencyKey);
        $fingerprint=$this->fingerprint(['ticket_id'=>$ticketId,'assignee_id'=>$assigneeId]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$ticketId,$assigneeId,$idempotencyKey,$assignmentId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'assign_ticket',$idempotencyKey,$fingerprint)){
                return ($this->service->viewTicket($organizationId,$ticketId)
                    ?? throw new InvalidArgumentException('Service assignment receipt exists but Ticket was not found.'))
                    + ['replayed'=>true];
            }
            $ticket=$this->service->lockTicket($organizationId,$ticketId);
            $newStatus=ServiceTicketLifecycle::assignmentStatus((string)$ticket['status']);
            $this->service->assignTicket($organizationId,$ticketId,$assignmentId,$assigneeId,$newStatus,$actorId);
            $this->publish(ServiceEventType::TICKET_ASSIGNED,$organizationId,'service_ticket',$ticketId,[
                'assignment_id'=>$assignmentId,'assignee_id'=>$assigneeId,'status'=>$newStatus,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'service.ticket.assign','service_ticket',$ticketId,$idempotencyKey,[
                'assignment_id'=>$assignmentId,'assignee_id'=>$assigneeId,
            ]);
            return $this->service->viewTicket($organizationId,$ticketId)
                ?? throw new InvalidArgumentException('Assigned Service ticket could not be read back.');
        });
    }

    public function setSla(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $ticketId,
        string $idempotencyKey,
        array $input,
    ): array {
        $ticketId=$this->bounded(trim($ticketId),'ticketId',80);
        $name=$this->required($input,'name',160);
        $responseMinutes=(int)($input['response_minutes']??-1);
        $resolutionMinutes=(int)($input['resolution_minutes']??-1);
        if($responseMinutes<0||$resolutionMinutes<$responseMinutes){
            throw new InvalidArgumentException('Invalid Service SLA durations.');
        }
        $slaId='SSLA-'.$this->stableId($organizationId.':sla:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'ticket_id'=>$ticketId,'name'=>$name,'response_minutes'=>$responseMinutes,'resolution_minutes'=>$resolutionMinutes,
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$ticketId,$idempotencyKey,$name,$responseMinutes,$resolutionMinutes,
            $slaId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'set_sla',$idempotencyKey,$fingerprint)){
                return ($this->service->viewTicket($organizationId,$ticketId)
                    ?? throw new InvalidArgumentException('Service SLA receipt exists but Ticket was not found.'))
                    + ['replayed'=>true];
            }
            $ticket=$this->service->lockTicket($organizationId,$ticketId);
            ServiceTicketLifecycle::assertMutable((string)$ticket['status'],'set SLA');
            $now=$this->now();
            $responseDue=$now->modify('+'.$responseMinutes.' minutes')->format('Y-m-d H:i:s.u');
            $resolutionDue=$now->modify('+'.$resolutionMinutes.' minutes')->format('Y-m-d H:i:s.u');
            $this->service->setSla(
                $organizationId,$ticketId,$slaId,$name,$responseMinutes,$resolutionMinutes,
                $responseDue,$resolutionDue,$actorId,
            );
            $this->publish(ServiceEventType::SLA_SET,$organizationId,'service_ticket',$ticketId,[
                'sla_id'=>$slaId,'name'=>$name,'response_minutes'=>$responseMinutes,
                'resolution_minutes'=>$resolutionMinutes,'response_due_at'=>$responseDue,'resolution_due_at'=>$resolutionDue,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'service.sla.set','service_ticket',$ticketId,$idempotencyKey,[
                'sla_id'=>$slaId,'response_due_at'=>$responseDue,'resolution_due_at'=>$resolutionDue,
            ]);
            return $this->service->viewTicket($organizationId,$ticketId)
                ?? throw new InvalidArgumentException('Service ticket with SLA could not be read back.');
        });
    }

    public function escalate(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $ticketId,
        string $reason,
        string $idempotencyKey,
    ): array {
        $ticketId=$this->bounded(trim($ticketId),'ticketId',80);
        $reason=$this->bounded(trim($reason),'reason',500);
        $escalationId='SESC-'.$this->stableId($organizationId.':escalation:'.$idempotencyKey);
        $fingerprint=$this->fingerprint(['ticket_id'=>$ticketId,'reason'=>$reason]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$ticketId,$reason,$idempotencyKey,$escalationId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'escalate_ticket',$idempotencyKey,$fingerprint)){
                return ($this->service->viewTicket($organizationId,$ticketId)
                    ?? throw new InvalidArgumentException('Service escalation receipt exists but Ticket was not found.'))
                    + ['replayed'=>true];
            }
            $ticket=$this->service->lockTicket($organizationId,$ticketId);
            ServiceTicketLifecycle::assertMutable((string)$ticket['status'],'be escalated');
            $level=(int)$ticket['escalation_level']+1;
            $this->service->escalate($organizationId,$ticketId,$escalationId,$level,$reason,$actorId);
            $this->publish(ServiceEventType::TICKET_ESCALATED,$organizationId,'service_ticket',$ticketId,[
                'escalation_id'=>$escalationId,'level'=>$level,'reason'=>$reason,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'service.ticket.escalate','service_ticket',$ticketId,$idempotencyKey,[
                'escalation_id'=>$escalationId,'level'=>$level,'reason'=>$reason,
            ]);
            return $this->service->viewTicket($organizationId,$ticketId)
                ?? throw new InvalidArgumentException('Escalated Service ticket could not be read back.');
        });
    }

    public function resolve(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $ticketId,
        string $summary,
        string $idempotencyKey,
    ): array {
        $ticketId=$this->bounded(trim($ticketId),'ticketId',80);
        $summary=$this->bounded(trim($summary),'summary',1000);
        $resolutionId='SRES-'.$this->stableId($organizationId.':resolution:'.$idempotencyKey);
        $fingerprint=$this->fingerprint(['ticket_id'=>$ticketId,'summary'=>$summary]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$ticketId,$summary,$idempotencyKey,$resolutionId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'resolve_ticket',$idempotencyKey,$fingerprint)){
                return ($this->service->viewTicket($organizationId,$ticketId)
                    ?? throw new InvalidArgumentException('Service resolution receipt exists but Ticket was not found.'))
                    + ['replayed'=>true];
            }
            $ticket=$this->service->lockTicket($organizationId,$ticketId);
            ServiceTicketLifecycle::assertResolvable((string)$ticket['status']);
            $this->service->resolve($organizationId,$ticketId,$resolutionId,$summary,$actorId);
            $this->publish(ServiceEventType::TICKET_RESOLVED,$organizationId,'service_ticket',$ticketId,[
                'resolution_id'=>$resolutionId,'summary'=>$summary,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'service.ticket.resolve','service_ticket',$ticketId,$idempotencyKey,[
                'resolution_id'=>$resolutionId,
            ]);
            return $this->service->viewTicket($organizationId,$ticketId)
                ?? throw new InvalidArgumentException('Resolved Service ticket could not be read back.');
        });
    }

    public function close(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $ticketId,
        string $idempotencyKey,
    ): array {
        $ticketId=$this->bounded(trim($ticketId),'ticketId',80);
        $fingerprint=$this->fingerprint(['ticket_id'=>$ticketId]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$ticketId,$idempotencyKey,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'close_ticket',$idempotencyKey,$fingerprint)){
                return ($this->service->viewTicket($organizationId,$ticketId)
                    ?? throw new InvalidArgumentException('Service close receipt exists but Ticket was not found.'))
                    + ['replayed'=>true];
            }
            $ticket=$this->service->lockTicket($organizationId,$ticketId);
            ServiceTicketLifecycle::assertClosable((string)$ticket['status']);
            $this->service->close($organizationId,$ticketId,$actorId);
            $this->service->closeCaseIfComplete($organizationId,$ticketId,$actorId);
            $this->publish(ServiceEventType::TICKET_CLOSED,$organizationId,'service_ticket',$ticketId,[
                'request_id'=>$ticket['request_id']??null,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'service.ticket.close','service_ticket',$ticketId,$idempotencyKey);
            return $this->service->viewTicket($organizationId,$ticketId)
                ?? throw new InvalidArgumentException('Closed Service ticket could not be read back.');
        });
    }

    public function viewRequest(string $organizationId, string $requestId): ?array
    {
        return $this->service->viewRequest($organizationId,$this->bounded(trim($requestId),'requestId',80));
    }

    public function viewTicket(string $organizationId, string $ticketId): ?array
    {
        return $this->service->viewTicket($organizationId,$this->bounded(trim($ticketId),'ticketId',80));
    }

    /** @param array<string,mixed> $payload */
    private function publish(
        string $type,
        string $organizationId,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        int $actorId,
        string $correlationId,
    ): void {
        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),
            $organizationId,
            $type,
            $aggregateType,
            $aggregateId,
            $payload,
            new EventMetadata($correlationId,null,'USER',(string)$actorId),
            $this->now(),
        ));
    }

    /** @param array<string,mixed> $data */
    private function appendAudit(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $action,
        string $subjectType,
        string $subjectId,
        string $idempotencyKey,
        array $data=[],
    ): void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),
            $organizationId,
            'service.mutation',
            'USER',
            (string)$actorId,
            $subjectType,
            $subjectId,
            null,
            [
                'action'=>$action,
                'idempotency_key_hash'=>hash('sha256',$idempotencyKey),
                'result'=>$data,
            ],
            $correlationId,
            $this->now(),
        ));
    }

    /** @param array<string,mixed> $input */
    private function required(array $input,string $key,int $limit): string
    {
        return $this->bounded(trim((string)($input[$key]??'')),$key,$limit);
    }

    /** @param array<string,mixed> $input */
    private function optional(array $input,string $key,int $limit): ?string
    {
        $value=trim((string)($input[$key]??''));
        return $value===''?null:$this->bounded($value,$key,$limit);
    }

    private function bounded(string $value,string $name,int $limit): string
    {
        if($value===''||mb_strlen($value)>$limit){
            throw new InvalidArgumentException($name.' is invalid.');
        }
        return $value;
    }

    private function stableId(string $value): string
    {
        return strtoupper(substr(hash('sha256',$value),0,20));
    }

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value): string
    {
        $normalize=function(mixed $item)use(&$normalize):mixed{
            if(!is_array($item))return $item;
            if(array_is_list($item))return array_map($normalize,$item);
            ksort($item,SORT_STRING);
            foreach($item as $key=>$nested)$item[$key]=$normalize($nested);
            return $item;
        };
        return hash('sha256',(string)json_encode(
            $normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now',new DateTimeZone('UTC'));
    }
}
