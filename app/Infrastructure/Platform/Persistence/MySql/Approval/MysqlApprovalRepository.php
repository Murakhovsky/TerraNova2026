<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Approval;

use Kernel\Action\Action;
use Kernel\Approval\Approval;
use Kernel\Approval\ApprovalStatus;
use Kernel\Approval\Contract\ApprovalRepositoryInterface;
use PDO;

final readonly class MysqlApprovalRepository implements ApprovalRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function createFor(Action $action, string $approverType, string $approverId, string $reason): Approval
    {
        $approval = new Approval(bin2hex(random_bytes(16)), $action->organizationId, $action->id, ApprovalStatus::Pending, $approverType, $approverId, $reason);
        $statement = $this->connection->prepare(
            'INSERT INTO cos_approvals (id, organization_id, action_id, status, approver_type, approver_id, '
            . 'requested_by_type, requested_by_id, reason) VALUES (:id, :organization_id, :action_id, '
            . "'PENDING', :approver_type, :approver_id, :requested_by_type, :requested_by_id, :reason)"
        );
        $statement->execute([
            'id' => $approval->id, 'organization_id' => $approval->organizationId, 'action_id' => $approval->actionId,
            'approver_type' => $approverType, 'approver_id' => $approverId,
            'requested_by_type' => in_array($action->sourceType, ['USER', 'AGENT', 'SYSTEM', 'INTEGRATION'], true) ? $action->sourceType : 'SYSTEM',
            'requested_by_id' => $action->sourceId, 'reason' => $reason,
        ]);
        return $approval;
    }

    public function findPending(string $organizationId, string $approvalId): ?Approval
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM cos_approvals WHERE organization_id = :organization_id AND id = :id AND status = 'PENDING' LIMIT 1"
        );
        $statement->execute(['organization_id' => $organizationId, 'id' => $approvalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : new Approval(
            (string) $row['id'], (string) $row['organization_id'], (string) $row['action_id'],
            ApprovalStatus::from((string) $row['status']), (string) $row['approver_type'],
            (string) $row['approver_id'], $row['reason'] !== null ? (string) $row['reason'] : null,
        );
    }

    public function decide(string $organizationId, string $approvalId, ApprovalStatus $decision, string $userId, ?string $note): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE cos_approvals SET status = :status, decided_by_type = \'USER\', decided_by_id = :user_id, '
            . 'decision_note = :note, decided_at = NOW(6) WHERE organization_id = :organization_id '
            . "AND id = :id AND status = 'PENDING'"
        );
        $statement->execute([
            'status' => $decision->value, 'user_id' => $userId, 'note' => $note,
            'organization_id' => $organizationId, 'id' => $approvalId,
        ]);
        return $statement->rowCount() === 1;
    }
}
