<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\MySql;

use InvalidArgumentException;
use Kernel\Identity\Contract\IdentityResolverInterface;
use Kernel\Identity\Model\AuthenticatedIdentity;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use PDO;

final readonly class MysqlIdentityRepository implements IdentityResolverInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function resolve(UserId $userId, OrganizationId $organizationId): ?AuthenticatedIdentity
    {
        $userIdValue = $userId->value();
        if (!ctype_digit($userIdValue) || (int) $userIdValue <= 0) {
            return null;
        }

        $statement = $this->connection->prepare(
            "SELECT id, email, status FROM tn_users WHERE id = :id AND status = 'active' LIMIT 1"
        );
        $statement->execute(['id' => (int) $userIdValue]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            return null;
        }

        $membership = $this->connection->prepare(
            "SELECT organization_id, role FROM cos_organization_memberships "
            . "WHERE user_id = :user_id AND organization_id = :organization_id "
            . "AND status = 'ACTIVE' LIMIT 1"
        );
        $membership->execute([
            'user_id' => (int) $userIdValue,
            'organization_id' => $organizationId->value(),
        ]);
        $row = $membership->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        try {
            $role = OrganizationRole::fromString((string) ($row['role'] ?? ''));
        } catch (InvalidArgumentException) {
            return null;
        }

        return new AuthenticatedIdentity(
            $userId,
            $organizationId,
            $role,
            (string) ($user['email'] ?? ''),
        );
    }
}
