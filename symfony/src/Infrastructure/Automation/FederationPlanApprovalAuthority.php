<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use DomainException;
use Kernel\Action\Action;
use Kernel\Approval\Approval;
use PDO;

/**
 * Federation's independent Plan Approval gate, before ApprovalService
 * records a decision. This is deliberately NOT a Domain Action handler.
 * Other action families keep their existing authority policies.
 */
final readonly class FederationPlanApprovalAuthority
{
    public function __construct(private PDO $connection) {}

    public function assertCanDecide(
        string $organizationId,
        string $userId,
        Approval $approval,
        Action $action,
    ): void {
        if ($action->type !== 'cos.federation.plan.approval') return;

        if ($action->sourceType !== 'USER'
            || $action->sourceId === $userId
            || !ctype_digit($userId)
            || (int) $userId <= 0
            || $approval->approverType !== 'ROLE'
            || $approval->approverId !== 'manager'
        ) {
            throw new DomainException('Federation Plan requires an independent tenant manager.');
        }

        $query = $this->connection->prepare(
            "SELECT 1 FROM cos_organization_memberships m
             INNER JOIN tn_users u ON u.id=m.user_id
             WHERE m.organization_id=:org AND m.user_id=:user
               AND m.status='ACTIVE' AND m.role IN ('admin','manager')
               AND u.status='active'
             LIMIT 1"
        );
        $query->execute(['org'=>$organizationId,'user'=>(int) $userId]);
        if ($query->fetchColumn() === false) {
            throw new DomainException('Federation approver must be an active manager in this tenant.');
        }
    }
}
