<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\MySql\Database\Policy;

use Kernel\Policy\ActionPolicy;
use Kernel\Policy\Contract\PolicyRepositoryInterface;
use Kernel\Policy\PolicyDecision;
use PDO;

final readonly class MysqlPolicyRepository implements PolicyRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function activeFor(string $organizationId, string $actionType): array
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM cos_policies WHERE organization_id = :organization_id AND status = 'ACTIVE' "
            . "AND (action_type = :action_type OR action_type = '*') ORDER BY priority, version DESC"
        );
        $statement->execute(['organization_id' => $organizationId, 'action_type' => $actionType]);
        return array_map(static fn (array $row): ActionPolicy => new ActionPolicy(
            (string) $row['id'], (string) $row['organization_id'], (string) $row['action_type'],
            json_decode((string) $row['conditions'], true, flags: JSON_THROW_ON_ERROR),
            PolicyDecision::from((string) $row['decision']), (int) $row['priority'],
        ), $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
