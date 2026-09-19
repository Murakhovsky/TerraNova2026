<?php
declare(strict_types=1);

namespace Domains\Service\Infrastructure\Persistence\MySql;

use Domains\Service\Application\Contract\ServiceRepositoryInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlServiceRepository implements ServiceRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function createRequestCase(array $case, array $request): void
    {
        $this->execute(
            'INSERT INTO tn_service_cases
             (organization_id,case_id,subject,status,created_by,updated_by)
             VALUES(:organization_id,:case_id,:subject,:status,:created_by,:updated_by)',
            $case,
        );
        $this->execute(
            'INSERT INTO tn_service_requests
             (organization_id,request_id,case_id,summary,requester_ref,status,created_by,updated_by)
             VALUES(:organization_id,:request_id,:case_id,:summary,:requester_ref,:status,:created_by,:updated_by)',
            $request,
        );
    }

    public function viewRequest(string $organizationId, string $requestId): ?array
    {
        $request=$this->one(
            'SELECT r.organization_id,r.request_id,r.case_id,r.summary,r.requester_ref,r.status,
                    r.created_by,r.updated_by,r.created_at,r.updated_at,
                    c.subject AS case_subject,c.status AS case_status
             FROM tn_service_requests r
             INNER JOIN tn_service_cases c
               ON c.organization_id=r.organization_id AND c.case_id=r.case_id
             WHERE r.organization_id=:organization_id AND r.request_id=:request_id
             LIMIT 1',
            ['organization_id'=>$organizationId,'request_id'=>$requestId],
        );
        if($request===null)return null;

        $request['tickets']=$this->all(
            'SELECT ticket_id,reference,subject,priority,status,assignee_id,escalation_level,
                    current_assignment_id,current_sla_id,current_resolution_id,
                    resolved_at,closed_at,created_at,updated_at
             FROM tn_service_tickets
             WHERE organization_id=:organization_id AND request_id=:request_id
             ORDER BY created_at,ticket_id',
            ['organization_id'=>$organizationId,'request_id'=>$requestId],
        );
        return $request;
    }

    public function createTicket(array $ticket): void
    {
        $this->execute(
            'INSERT INTO tn_service_tickets
             (organization_id,ticket_id,request_id,reference,subject,priority,status,
              escalation_level,created_by,updated_by)
             VALUES(:organization_id,:ticket_id,:request_id,:reference,:subject,:priority,:status,
                    :escalation_level,:created_by,:updated_by)',
            $ticket,
        );
    }

    public function viewTicket(string $organizationId, string $ticketId): ?array
    {
        $ticket=$this->one(
            'SELECT t.organization_id,t.ticket_id,t.request_id,t.reference,t.subject,t.priority,t.status,
                    t.assignee_id,t.escalation_level,t.current_assignment_id,t.current_sla_id,
                    t.current_resolution_id,t.created_by,t.updated_by,t.resolved_at,t.closed_at,
                    t.created_at,t.updated_at,r.case_id,r.summary AS request_summary,r.status AS request_status,
                    c.subject AS case_subject,c.status AS case_status,
                    s.name AS sla_name,s.response_minutes,s.resolution_minutes,s.response_due_at,s.resolution_due_at,
                    z.summary AS resolution_summary,z.resolved_by,z.resolved_at AS resolution_recorded_at
             FROM tn_service_tickets t
             INNER JOIN tn_service_requests r
               ON r.organization_id=t.organization_id AND r.request_id=t.request_id
             INNER JOIN tn_service_cases c
               ON c.organization_id=r.organization_id AND c.case_id=r.case_id
             LEFT JOIN tn_service_slas s
               ON s.organization_id=t.organization_id AND s.sla_id=t.current_sla_id
             LEFT JOIN tn_service_resolutions z
               ON z.organization_id=t.organization_id AND z.resolution_id=t.current_resolution_id
             WHERE t.organization_id=:organization_id AND t.ticket_id=:ticket_id
             LIMIT 1',
            ['organization_id'=>$organizationId,'ticket_id'=>$ticketId],
        );
        if($ticket===null)return null;

        $ticket['assignments']=$this->all(
            'SELECT assignment_id,assignee_id,assigned_by,active,assigned_at,ended_at
             FROM tn_service_assignments
             WHERE organization_id=:organization_id AND ticket_id=:ticket_id
             ORDER BY assigned_at,assignment_id',
            ['organization_id'=>$organizationId,'ticket_id'=>$ticketId],
        );
        $ticket['slas']=$this->all(
            'SELECT sla_id,name,response_minutes,resolution_minutes,response_due_at,resolution_due_at,set_by,created_at
             FROM tn_service_slas
             WHERE organization_id=:organization_id AND ticket_id=:ticket_id
             ORDER BY created_at,sla_id',
            ['organization_id'=>$organizationId,'ticket_id'=>$ticketId],
        );
        $ticket['escalations']=$this->all(
            'SELECT escalation_id,level,reason,escalated_by,created_at
             FROM tn_service_escalations
             WHERE organization_id=:organization_id AND ticket_id=:ticket_id
             ORDER BY level,created_at',
            ['organization_id'=>$organizationId,'ticket_id'=>$ticketId],
        );
        return $ticket;
    }

    public function lockTicket(string $organizationId, string $ticketId): array
    {
        $row=$this->one(
            'SELECT organization_id,ticket_id,request_id,status,assignee_id,escalation_level,
                    current_assignment_id,current_sla_id,current_resolution_id
             FROM tn_service_tickets
             WHERE organization_id=:organization_id AND ticket_id=:ticket_id
             LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'ticket_id'=>$ticketId],
        );
        if($row===null)throw new InvalidArgumentException('Service ticket was not found.');
        return $row;
    }

    public function assignTicket(
        string $organizationId,
        string $ticketId,
        string $assignmentId,
        string $assigneeId,
        string $newStatus,
        int $actorId,
    ): void {
        $this->execute(
            'UPDATE tn_service_assignments
             SET active=0,ended_at=NOW(6)
             WHERE organization_id=:organization_id AND ticket_id=:ticket_id AND active=1',
            ['organization_id'=>$organizationId,'ticket_id'=>$ticketId],
        );
        $this->execute(
            'INSERT INTO tn_service_assignments
             (organization_id,assignment_id,ticket_id,assignee_id,assigned_by,active,assigned_at)
             VALUES(:organization_id,:assignment_id,:ticket_id,:assignee_id,:assigned_by,1,NOW(6))',
            [
                'organization_id'=>$organizationId,'assignment_id'=>$assignmentId,'ticket_id'=>$ticketId,
                'assignee_id'=>$assigneeId,'assigned_by'=>$actorId,
            ],
        );
        $this->execute(
            'UPDATE tn_service_tickets
             SET current_assignment_id=:assignment_id,assignee_id=:assignee_id,status=:new_status,
                 updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND ticket_id=:ticket_id',
            [
                'assignment_id'=>$assignmentId,'assignee_id'=>$assigneeId,'new_status'=>$newStatus,
                'updated_by'=>$actorId,'organization_id'=>$organizationId,'ticket_id'=>$ticketId,
            ],
        );
    }

    public function setSla(
        string $organizationId,
        string $ticketId,
        string $slaId,
        string $name,
        int $responseMinutes,
        int $resolutionMinutes,
        string $responseDueAt,
        string $resolutionDueAt,
        int $actorId,
    ): void {
        $this->execute(
            'INSERT INTO tn_service_slas
             (organization_id,sla_id,ticket_id,name,response_minutes,resolution_minutes,
              response_due_at,resolution_due_at,set_by)
             VALUES(:organization_id,:sla_id,:ticket_id,:name,:response_minutes,:resolution_minutes,
                    :response_due_at,:resolution_due_at,:set_by)',
            [
                'organization_id'=>$organizationId,'sla_id'=>$slaId,'ticket_id'=>$ticketId,'name'=>$name,
                'response_minutes'=>$responseMinutes,'resolution_minutes'=>$resolutionMinutes,
                'response_due_at'=>$responseDueAt,'resolution_due_at'=>$resolutionDueAt,'set_by'=>$actorId,
            ],
        );
        $this->execute(
            'UPDATE tn_service_tickets
             SET current_sla_id=:sla_id,updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND ticket_id=:ticket_id',
            [
                'sla_id'=>$slaId,'updated_by'=>$actorId,
                'organization_id'=>$organizationId,'ticket_id'=>$ticketId,
            ],
        );
    }

    public function escalate(
        string $organizationId,
        string $ticketId,
        string $escalationId,
        int $level,
        string $reason,
        int $actorId,
    ): void {
        $this->execute(
            'INSERT INTO tn_service_escalations
             (organization_id,escalation_id,ticket_id,level,reason,escalated_by)
             VALUES(:organization_id,:escalation_id,:ticket_id,:level,:reason,:escalated_by)',
            [
                'organization_id'=>$organizationId,'escalation_id'=>$escalationId,'ticket_id'=>$ticketId,
                'level'=>$level,'reason'=>$reason,'escalated_by'=>$actorId,
            ],
        );
        $this->execute(
            'UPDATE tn_service_tickets
             SET status=\'escalated\',escalation_level=:level,updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND ticket_id=:ticket_id',
            [
                'level'=>$level,'updated_by'=>$actorId,
                'organization_id'=>$organizationId,'ticket_id'=>$ticketId,
            ],
        );
    }

    public function resolve(
        string $organizationId,
        string $ticketId,
        string $resolutionId,
        string $summary,
        int $actorId,
    ): void {
        $this->execute(
            'INSERT INTO tn_service_resolutions
             (organization_id,resolution_id,ticket_id,summary,resolved_by,resolved_at)
             VALUES(:organization_id,:resolution_id,:ticket_id,:summary,:resolved_by,NOW(6))',
            [
                'organization_id'=>$organizationId,'resolution_id'=>$resolutionId,'ticket_id'=>$ticketId,
                'summary'=>$summary,'resolved_by'=>$actorId,
            ],
        );
        $this->execute(
            'UPDATE tn_service_tickets
             SET status=\'resolved\',current_resolution_id=:resolution_id,resolved_at=NOW(6),
                 updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND ticket_id=:ticket_id',
            [
                'resolution_id'=>$resolutionId,'updated_by'=>$actorId,
                'organization_id'=>$organizationId,'ticket_id'=>$ticketId,
            ],
        );
    }

    public function close(string $organizationId, string $ticketId, int $actorId): void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_service_tickets
             SET status=\'closed\',closed_at=NOW(6),updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND ticket_id=:ticket_id AND status=\'resolved\''
        );
        $statement->execute([
            'updated_by'=>$actorId,'organization_id'=>$organizationId,'ticket_id'=>$ticketId,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Service ticket could not be closed.');
    }

    public function closeCaseIfComplete(string $organizationId, string $ticketId, int $actorId): void
    {
        $row=$this->one(
            'SELECT r.request_id,r.case_id
             FROM tn_service_tickets t
             INNER JOIN tn_service_requests r
               ON r.organization_id=t.organization_id AND r.request_id=t.request_id
             WHERE t.organization_id=:organization_id AND t.ticket_id=:ticket_id LIMIT 1',
            ['organization_id'=>$organizationId,'ticket_id'=>$ticketId],
        );
        if($row===null)return;

        $requestId=(string)$row['request_id'];
        $caseId=(string)$row['case_id'];

        $openTickets=(int)$this->scalar(
            'SELECT COUNT(*) FROM tn_service_tickets
             WHERE organization_id=:organization_id AND request_id=:request_id AND status<>\'closed\'',
            ['organization_id'=>$organizationId,'request_id'=>$requestId],
        );
        if($openTickets===0){
            $this->execute(
                'UPDATE tn_service_requests
                 SET status=\'closed\',updated_by=:updated_by,updated_at=NOW(6)
                 WHERE organization_id=:organization_id AND request_id=:request_id',
                ['updated_by'=>$actorId,'organization_id'=>$organizationId,'request_id'=>$requestId],
            );
        }

        $openRequests=(int)$this->scalar(
            'SELECT COUNT(*) FROM tn_service_requests
             WHERE organization_id=:organization_id AND case_id=:case_id AND status<>\'closed\'',
            ['organization_id'=>$organizationId,'case_id'=>$caseId],
        );
        if($openRequests===0){
            $this->execute(
                'UPDATE tn_service_cases
                 SET status=\'closed\',updated_by=:updated_by,updated_at=NOW(6)
                 WHERE organization_id=:organization_id AND case_id=:case_id',
                ['updated_by'=>$actorId,'organization_id'=>$organizationId,'case_id'=>$caseId],
            );
        }
    }

    private function execute(string $sql,array $params): void
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
    }

    private function one(string $sql,array $params): ?array
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    /** @return list<array<string,mixed>> */
    private function all(string $sql,array $params): array
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    private function scalar(string $sql,array $params): string|int|false
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }
}
