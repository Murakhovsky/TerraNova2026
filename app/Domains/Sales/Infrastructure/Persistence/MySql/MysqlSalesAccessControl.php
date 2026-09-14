<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\SalesAccessControlInterface;
use Domains\Sales\Model\SalesCapability;
use PDO;

final readonly class MysqlSalesAccessControl implements SalesAccessControlInterface
{
    public function __construct(private PDO $connection) {}

    public function hasCapability(string $organizationId, int $userId, string $capability): bool
    {
        if (!in_array($capability, SalesCapability::values(), true)) return false;
        $statement = $this->connection->prepare(
            'SELECT 1
             FROM tn_users u
             INNER JOIN cos_organization_memberships m
                ON m.organization_id=u.organization_id AND m.user_id=u.id AND m.status="ACTIVE"
             LEFT JOIN sales_user_capabilities c
                ON c.organization_id=u.organization_id AND c.user_id=u.id
               AND c.capability=:capability AND c.status="ACTIVE"
             WHERE u.organization_id=:organization_id AND u.id=:user_id AND u.status="active"
               AND (u.role="admin" OR c.capability IS NOT NULL)
             LIMIT 1'
        );
        $statement->execute([
            'organization_id'=>$organizationId,
            'user_id'=>$userId,
            'capability'=>$capability,
        ]);
        return $statement->fetchColumn() !== false;
    }
}
