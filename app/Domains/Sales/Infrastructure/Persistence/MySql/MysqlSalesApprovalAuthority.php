<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Sales\Model\SalesCapability;
use Kernel\Action\Action;
use Kernel\Approval\Approval;
use Kernel\Approval\Contract\ApprovalAuthorityInterface;
use PDO;

final readonly class MysqlSalesApprovalAuthority implements ApprovalAuthorityInterface
{
    public function __construct(private PDO $connection) {}

    public function assertCanDecide(string $organizationId, string $userId, Approval $approval, Action $action): void
    {
        if (!str_starts_with($action->type, 'sales.')) {
            return;
        }

        $user = (int) $userId;
        if ($user <= 0 || !$this->activeMember($organizationId, $user)) {
            throw new DomainException('Approval actor is not an active organization member.');
        }

        if ($this->hasCapability($organizationId, $user, SalesCapability::ApprovalAnyTeam->value)) {
            return;
        }

        if (!$this->hasCapability($organizationId, $user, SalesCapability::ApprovalDecide->value)
            && !$this->isApprovalLead($organizationId, $user)) {
            throw new DomainException('User does not have Sales approval authority.');
        }

        if ($action->targetType !== 'deal' || $action->targetId === null || !ctype_digit($action->targetId)) {
            return;
        }

        $statement = $this->connection->prepare(
            'SELECT 1
             FROM tn_client_cases deal
             INNER JOIN sales_team_members owner_member
                ON owner_member.organization_id = deal.organization_id
               AND owner_member.user_id = deal.assigned_user_id
               AND owner_member.status = "ACTIVE"
             INNER JOIN sales_team_members approver_member
                ON approver_member.organization_id = owner_member.organization_id
               AND approver_member.team_id = owner_member.team_id
               AND approver_member.user_id = :user_id
               AND approver_member.status = "ACTIVE"
               AND approver_member.approval_enabled = 1
             INNER JOIN sales_teams team
                ON team.organization_id = owner_member.organization_id
               AND team.id = owner_member.team_id
               AND team.status = "ACTIVE"
             WHERE deal.organization_id = :organization_id AND deal.id = :deal_id
             LIMIT 1'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'deal_id' => (int) $action->targetId,
            'user_id' => $user,
        ]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('User cannot approve Sales actions outside their team.');
        }
    }

    private function activeMember(string $organizationId, int $userId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM cos_organization_memberships
             WHERE organization_id = :organization_id AND user_id = :user_id AND status = "ACTIVE" LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'user_id' => $userId]);
        return $statement->fetchColumn() !== false;
    }

    private function isApprovalLead(string $organizationId, int $userId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1
             FROM sales_team_members member
             INNER JOIN sales_teams team ON team.id = member.team_id AND team.organization_id = member.organization_id
             WHERE member.organization_id = :organization_id AND member.user_id = :user_id
               AND member.status = "ACTIVE" AND member.role = "LEAD" AND member.approval_enabled = 1
               AND team.status = "ACTIVE" LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'user_id' => $userId]);
        return $statement->fetchColumn() !== false;
    }

    private function hasCapability(string $organizationId, int $userId, string $capability): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM sales_user_capabilities
             WHERE organization_id = :organization_id AND user_id = :user_id
               AND capability = :capability AND status = "ACTIVE" LIMIT 1'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'capability' => $capability,
        ]);
        return $statement->fetchColumn() !== false;
    }
}
