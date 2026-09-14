<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Sales\Application\Contract\SalesAssignmentAuthorityInterface;
use Domains\Sales\Model\SalesCapability;
use PDO;

final readonly class MysqlSalesAssignmentAuthority implements SalesAssignmentAuthorityInterface
{
    public function __construct(private PDO $connection) {}

    public function assertCanAssign(string $organizationId, string $actorType, string $actorId, int $ownerId): void
    {
        if (!$this->isAssignable($organizationId, $ownerId)) {
            throw new DomainException('Target owner is not an active assignable Sales team member.');
        }

        if (strtoupper($actorType) !== 'USER') {
            return;
        }

        $actor = (int) $actorId;
        if ($actor <= 0) {
            throw new DomainException('A valid user actor is required for manual assignment.');
        }

        if ($this->hasCapability($organizationId, $actor, SalesCapability::DealAssign->value)) {
            return;
        }

        $statement = $this->connection->prepare(
            'SELECT 1
             FROM sales_team_members actor_member
             INNER JOIN sales_teams team
                ON team.id = actor_member.team_id
               AND team.organization_id = actor_member.organization_id
               AND team.status = "ACTIVE"
             INNER JOIN sales_team_members target_member
                ON target_member.team_id = actor_member.team_id
               AND target_member.organization_id = actor_member.organization_id
               AND target_member.user_id = :owner_id
               AND target_member.status = "ACTIVE"
               AND target_member.assignment_enabled = 1
             WHERE actor_member.organization_id = :organization_id
               AND actor_member.user_id = :actor_id
               AND actor_member.status = "ACTIVE"
               AND actor_member.role = "LEAD"
             LIMIT 1'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'actor_id' => $actor,
            'owner_id' => $ownerId,
        ]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('User does not have authority to assign this Sales owner.');
        }
    }

    private function isAssignable(string $organizationId, int $userId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1
             FROM tn_users user
             INNER JOIN cos_organization_memberships membership
                ON membership.organization_id = user.organization_id
               AND membership.user_id = user.id
               AND membership.status = "ACTIVE"
             INNER JOIN sales_team_members team_member
                ON team_member.organization_id = user.organization_id
               AND team_member.user_id = user.id
               AND team_member.status = "ACTIVE"
               AND team_member.assignment_enabled = 1
             INNER JOIN sales_teams team
                ON team.id = team_member.team_id
               AND team.organization_id = team_member.organization_id
               AND team.status = "ACTIVE"
             WHERE user.organization_id = :organization_id
               AND user.id = :user_id
               AND user.status = "active"
             LIMIT 1'
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
