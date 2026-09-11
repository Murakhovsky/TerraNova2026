<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Sales\Application\Contract\SalesTeamAdministrationInterface;
use Domains\Sales\Model\SalesCapability;
use PDO;
use Throwable;

final readonly class MysqlSalesTeamAdministration implements SalesTeamAdministrationInterface
{
    public function __construct(private PDO $connection) {}

    public function catalog(): array
    {
        return [
            'team_statuses' => ['ACTIVE', 'DISABLED', 'ARCHIVED'],
            'member_roles' => ['MEMBER', 'LEAD'],
            'member_statuses' => ['ACTIVE', 'INACTIVE'],
            'assignment_modes' => ['MANUAL', 'ROUND_ROBIN'],
            'capabilities' => SalesCapability::values(),
        ];
    }

    public function users(string $organizationId): array
    {
        $statement = $this->connection->prepare(
            'SELECT u.id,u.email,u.full_name,u.role,u.status,m.role AS organization_role,m.status AS membership_status
             FROM tn_users u
             INNER JOIN cos_organization_memberships m ON m.organization_id=u.organization_id AND m.user_id=u.id
             WHERE u.organization_id=:organization_id ORDER BY u.full_name,u.id'
        );
        $statement->execute(['organization_id' => $organizationId]);
        $users = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($users as &$user) {
            $user['teams'] = $this->userTeams($organizationId, (int) $user['id']);
            $user['capabilities'] = $this->userCapabilities($organizationId, (int) $user['id']);
        }
        unset($user);
        return $users;
    }

    public function teams(string $organizationId): array
    {
        $statement = $this->connection->prepare(
            'SELECT t.id,t.code,t.name,t.status,t.assignment_mode,t.configuration_version,t.created_at,t.updated_at,
                    COUNT(CASE WHEN m.status="ACTIVE" THEN 1 END) AS active_members,
                    COUNT(CASE WHEN m.status="ACTIVE" AND m.role="LEAD" THEN 1 END) AS leads
             FROM sales_teams t
             LEFT JOIN sales_team_members m ON m.organization_id=t.organization_id AND m.team_id=t.id
             WHERE t.organization_id=:organization_id
             GROUP BY t.id,t.code,t.name,t.status,t.assignment_mode,t.configuration_version,t.created_at,t.updated_at
             ORDER BY t.status="ACTIVE" DESC,t.name,t.id'
        );
        $statement->execute(['organization_id' => $organizationId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function team(string $organizationId, string $teamId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id,code,name,status,assignment_mode,configuration_version,created_by,updated_by,created_at,updated_at
             FROM sales_teams WHERE organization_id=:organization_id AND id=:id LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'id' => $teamId]);
        $team = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($team)) return null;
        $team['members'] = $this->teamMembers($organizationId, $teamId);
        return $team;
    }

    public function createTeam(string $organizationId, array $input, string $actorId): array
    {
        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 160);
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        if ($name === '' || !preg_match('/^[A-Z0-9_]{2,80}$/', $code)) {
            throw new DomainException('Team name and stable code are required.');
        }
        $mode = $this->assignmentMode((string) ($input['assignment_mode'] ?? 'MANUAL'));
        $id = bin2hex(random_bytes(16));
        $this->transactional(function () use ($organizationId, $id, $code, $name, $mode, $actorId): void {
            $statement = $this->connection->prepare(
                'INSERT INTO sales_teams
                 (id,organization_id,code,name,status,assignment_mode,configuration_version,created_by,updated_by,created_at,updated_at)
                 VALUES (:id,:organization_id,:code,:name,"ACTIVE",:assignment_mode,1,:actor_id,:actor_id,NOW(6),NOW(6))'
            );
            $statement->execute([
                'id' => $id,
                'organization_id' => $organizationId,
                'code' => $code,
                'name' => $name,
                'assignment_mode' => $mode,
                'actor_id' => $actorId,
            ]);
            $this->revision($organizationId, 'TEAM', $id, 1, 'CREATE', $actorId, null, [
                'id' => $id, 'code' => $code, 'name' => $name, 'status' => 'ACTIVE', 'assignment_mode' => $mode,
            ]);
        });
        return $this->team($organizationId, $id) ?? [];
    }

    public function updateTeam(string $organizationId, string $teamId, array $input, int $expectedVersion, string $actorId): array
    {
        $current = $this->team($organizationId, $teamId);
        if ($current === null) throw new DomainException('Sales team was not found.');
        if ($expectedVersion <= 0 || (int) $current['configuration_version'] !== $expectedVersion) {
            throw new DomainException('CONFIGURATION_CONFLICT');
        }
        $name = mb_substr(trim((string) ($input['name'] ?? $current['name'])), 0, 160);
        $status = strtoupper(trim((string) ($input['status'] ?? $current['status'])));
        if ($name === '' || !in_array($status, ['ACTIVE', 'DISABLED', 'ARCHIVED'], true)) throw new DomainException('Invalid team configuration.');
        $mode = $this->assignmentMode((string) ($input['assignment_mode'] ?? $current['assignment_mode']));
        $nextVersion = $expectedVersion + 1;
        $this->transactional(function () use ($organizationId, $teamId, $name, $status, $mode, $actorId, $expectedVersion, $nextVersion, $current): void {
            $statement = $this->connection->prepare(
                'UPDATE sales_teams SET name=:name,status=:status,assignment_mode=:assignment_mode,
                    configuration_version=:next_version,updated_by=:actor_id,updated_at=NOW(6)
                 WHERE organization_id=:organization_id AND id=:id AND configuration_version=:expected_version'
            );
            $statement->execute([
                'name'=>$name,'status'=>$status,'assignment_mode'=>$mode,'next_version'=>$nextVersion,'actor_id'=>$actorId,
                'organization_id'=>$organizationId,'id'=>$teamId,'expected_version'=>$expectedVersion,
            ]);
            if ($statement->rowCount() !== 1) throw new DomainException('CONFIGURATION_CONFLICT');
            $this->revision($organizationId, 'TEAM', $teamId, $nextVersion, $status === 'ARCHIVED' ? 'ARCHIVE' : ($status === 'DISABLED' ? 'DISABLE' : 'UPDATE'), $actorId, $current, [
                'id'=>$teamId,'code'=>$current['code'],'name'=>$name,'status'=>$status,'assignment_mode'=>$mode,'configuration_version'=>$nextVersion,
            ]);
        });
        return $this->team($organizationId, $teamId) ?? [];
    }

    public function setMember(string $organizationId, string $teamId, int $userId, array $input, string $actorId): array
    {
        if ($this->team($organizationId, $teamId) === null) throw new DomainException('Sales team was not found.');
        $this->assertOrganizationUser($organizationId, $userId);
        $role = strtoupper(trim((string) ($input['role'] ?? 'MEMBER')));
        $status = strtoupper(trim((string) ($input['status'] ?? 'ACTIVE')));
        if (!in_array($role, ['MEMBER','LEAD'], true) || !in_array($status, ['ACTIVE','INACTIVE'], true)) throw new DomainException('Invalid team membership.');
        $assignment = !empty($input['assignment_enabled']) ? 1 : 0;
        $approval = !empty($input['approval_enabled']) ? 1 : 0;
        $weight = max(1, min(100, (int) ($input['assignment_weight'] ?? 1)));
        $before = $this->member($organizationId, $teamId, $userId);
        $statement = $this->connection->prepare(
            'INSERT INTO sales_team_members
             (organization_id,team_id,user_id,role,status,assignment_enabled,approval_enabled,assignment_weight,created_at,updated_at)
             VALUES (:organization_id,:team_id,:user_id,:role,:status,:assignment_enabled,:approval_enabled,:assignment_weight,NOW(6),NOW(6))
             ON DUPLICATE KEY UPDATE role=VALUES(role),status=VALUES(status),assignment_enabled=VALUES(assignment_enabled),
                approval_enabled=VALUES(approval_enabled),assignment_weight=VALUES(assignment_weight),updated_at=NOW(6)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'team_id'=>$teamId,'user_id'=>$userId,'role'=>$role,'status'=>$status,
            'assignment_enabled'=>$assignment,'approval_enabled'=>$approval,'assignment_weight'=>$weight,
        ]);
        $after = $this->member($organizationId, $teamId, $userId) ?? [];
        $this->revision($organizationId, 'ASSIGNMENT', $teamId . ':' . $userId, 1, $before === null ? 'CREATE' : 'UPDATE', $actorId, $before, $after);
        return $after;
    }

    public function setCapabilities(string $organizationId, int $userId, array $capabilities, string $actorId): array
    {
        $this->assertOrganizationUser($organizationId, $userId);
        $allowed = SalesCapability::values();
        $wanted = array_values(array_unique(array_filter(array_map('strval', $capabilities))));
        foreach ($wanted as $capability) {
            if (!in_array($capability, $allowed, true)) throw new DomainException('Unsupported Sales capability: ' . $capability);
        }
        $before = $this->userCapabilities($organizationId, $userId);
        $this->transactional(function () use ($organizationId, $userId, $wanted, $actorId, $before): void {
            $this->connection->prepare('DELETE FROM sales_user_capabilities WHERE organization_id=:organization_id AND user_id=:user_id')
                ->execute(['organization_id'=>$organizationId,'user_id'=>$userId]);
            $insert = $this->connection->prepare(
                'INSERT INTO sales_user_capabilities (organization_id,user_id,capability,status,granted_by,created_at,updated_at)
                 VALUES (:organization_id,:user_id,:capability,"ACTIVE",:actor_id,NOW(6),NOW(6))'
            );
            foreach ($wanted as $capability) $insert->execute(['organization_id'=>$organizationId,'user_id'=>$userId,'capability'=>$capability,'actor_id'=>$actorId]);
            $this->revision($organizationId, 'ASSIGNMENT', 'capabilities:' . $userId, 1, 'UPDATE', $actorId, ['capabilities'=>$before], ['capabilities'=>$wanted]);
        });
        return $this->userCapabilities($organizationId, $userId);
    }

    public function revisions(string $organizationId, string $entityId, int $limit = 100): array
    {
        $statement = $this->connection->prepare(
            'SELECT id,configuration_type,entity_id,entity_version,action,actor_type,actor_id,reason,before_payload,after_payload,created_at
             FROM cos_configuration_revisions
             WHERE organization_id=:organization_id AND domain_name="sales" AND entity_id=:entity_id
               AND configuration_type IN ("TEAM","ASSIGNMENT") ORDER BY id DESC LIMIT :limit'
        );
        $statement->bindValue(':organization_id', $organizationId);
        $statement->bindValue(':entity_id', $entityId);
        $statement->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function teamMembers(string $organizationId, string $teamId): array
    {
        $statement = $this->connection->prepare(
            'SELECT m.user_id,m.role,m.status,m.assignment_enabled,m.approval_enabled,m.assignment_weight,
                    u.full_name,u.email,u.role AS user_role,u.status AS user_status
             FROM sales_team_members m INNER JOIN tn_users u ON u.id=m.user_id AND u.organization_id=m.organization_id
             WHERE m.organization_id=:organization_id AND m.team_id=:team_id ORDER BY m.role="LEAD" DESC,u.full_name,u.id'
        );
        $statement->execute(['organization_id'=>$organizationId,'team_id'=>$teamId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function userTeams(string $organizationId, int $userId): array
    {
        $statement = $this->connection->prepare(
            'SELECT m.team_id,t.code,t.name,m.role,m.status,m.assignment_enabled,m.approval_enabled,m.assignment_weight
             FROM sales_team_members m INNER JOIN sales_teams t ON t.id=m.team_id AND t.organization_id=m.organization_id
             WHERE m.organization_id=:organization_id AND m.user_id=:user_id ORDER BY t.name,t.id'
        );
        $statement->execute(['organization_id'=>$organizationId,'user_id'=>$userId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function member(string $organizationId, string $teamId, int $userId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT organization_id,team_id,user_id,role,status,assignment_enabled,approval_enabled,assignment_weight
             FROM sales_team_members WHERE organization_id=:organization_id AND team_id=:team_id AND user_id=:user_id LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'team_id'=>$teamId,'user_id'=>$userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function userCapabilities(string $organizationId, int $userId): array
    {
        $statement = $this->connection->prepare(
            'SELECT capability FROM sales_user_capabilities
             WHERE organization_id=:organization_id AND user_id=:user_id AND status="ACTIVE" ORDER BY capability'
        );
        $statement->execute(['organization_id'=>$organizationId,'user_id'=>$userId]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function assertOrganizationUser(string $organizationId, int $userId): void
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM tn_users u INNER JOIN cos_organization_memberships m ON m.organization_id=u.organization_id AND m.user_id=u.id
             WHERE u.organization_id=:organization_id AND u.id=:user_id AND u.status="active" AND m.status="ACTIVE" LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'user_id'=>$userId]);
        if ($statement->fetchColumn() === false) throw new DomainException('User is not an active member of this organization.');
    }

    private function assignmentMode(string $mode): string
    {
        $mode = strtoupper(trim($mode));
        if (!in_array($mode, ['MANUAL','ROUND_ROBIN'], true)) throw new DomainException('Unsupported assignment mode.');
        return $mode;
    }

    private function revision(string $organizationId, string $type, string $entityId, int $version, string $action, string $actorId, ?array $before, array $after): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_configuration_revisions
             (organization_id,domain_name,configuration_type,entity_id,entity_version,action,actor_type,actor_id,reason,before_payload,after_payload,created_at)
             VALUES (:organization_id,"sales",:type,:entity_id,:version,:action,"USER",:actor_id,NULL,:before_payload,:after_payload,NOW(6))'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'type'=>$type,'entity_id'=>$entityId,'version'=>$version,'action'=>$action,'actor_id'=>$actorId,
            'before_payload'=>$before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_payload'=>json_encode($after, JSON_THROW_ON_ERROR),
        ]);
    }

    private function transactional(callable $callback): mixed
    {
        $owns = !$this->connection->inTransaction();
        if ($owns) $this->connection->beginTransaction();
        try {
            $result = $callback();
            if ($owns) $this->connection->commit();
            return $result;
        } catch (Throwable $error) {
            if ($owns && $this->connection->inTransaction()) $this->connection->rollBack();
            throw $error;
        }
    }
}
