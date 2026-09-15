<?php
declare(strict_types=1);

namespace Domains\Identity\Infrastructure\Persistence\MySql;

use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use RuntimeException;

/**
 * Keeps the home-organization membership aligned with the legacy tn_users role/status projection.
 *
 * Workspace authorization is membership-owned. tn_users.role remains a compatibility projection
 * until Identity gets a fully organization-scoped administration surface.
 */
final readonly class OrganizationMembershipSynchronizer
{
    public function __construct(private PdoConnection $database)
    {
    }

    public function syncHomeMembership(int $userId): void
    {
        $user = $this->database->fetchOne(
            'SELECT id, organization_id, role, status FROM tn_users WHERE id = :id LIMIT 1',
            ['id' => $userId],
        );

        if ($user === null) {
            throw new RuntimeException(sprintf('Cannot synchronize organization membership for missing user %d.', $userId));
        }

        $organizationId = trim((string) ($user['organization_id'] ?? ''));
        $role = trim((string) ($user['role'] ?? ''));
        if ($organizationId === '' || $role === '') {
            throw new RuntimeException(sprintf('User %d has incomplete organization identity.', $userId));
        }

        $membershipStatus = match ((string) ($user['status'] ?? '')) {
            'active' => 'ACTIVE',
            'pending' => 'INVITED',
            'blocked' => 'SUSPENDED',
            default => 'SUSPENDED',
        };

        $statement = $this->database->connection()->prepare(<<<'SQL'
INSERT INTO cos_organization_memberships (organization_id, user_id, role, status)
VALUES (:organization_id, :user_id, :role, :status)
ON DUPLICATE KEY UPDATE
    role = VALUES(role),
    status = VALUES(status),
    updated_at = CURRENT_TIMESTAMP(6)
SQL);
        $statement->execute([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'role' => $role,
            'status' => $membershipStatus,
        ]);
    }
}
