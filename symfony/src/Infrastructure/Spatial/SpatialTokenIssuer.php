<?php

declare(strict_types=1);

namespace App\Infrastructure\Spatial;

use App\Security\SpatialJwtCodec;
use PDO;

final readonly class SpatialTokenIssuer
{
    private const EDIT_ROLES = ['admin', 'manager', 'realtor', 'partner', 'developer'];

    public function __construct(
        private PDO $connection,
        private SpatialJwtCodec $tokens,
        private string $organizationId,
        private int $ttl,
    ) {
    }

    public function issue(array $credentials): array
    {
        $email = mb_strtolower(trim((string) ($credentials['email'] ?? '')));
        $password = (string) ($credentials['password'] ?? '');

        if ($email === '' || $password === '') {
            return ['ok' => false, 'status' => 401, 'message' => 'Invalid credentials or insufficient access.'];
        }

        $statement = $this->connection->prepare(
            'SELECT u.id,u.email,u.full_name,u.password_hash,u.status,m.role '
            . 'FROM tn_users u '
            . 'INNER JOIN cos_organization_memberships m ON m.user_id=u.id '
            . 'AND m.organization_id=:organization_id AND m.status="ACTIVE" '
            . 'WHERE u.email=:email AND u.status="active" LIMIT 1'
        );
        $statement->execute([
            'organization_id' => $this->organizationId,
            'email' => $email,
        ]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user)
            || !password_verify($password, (string) ($user['password_hash'] ?? ''))
            || !in_array((string) ($user['role'] ?? ''), self::EDIT_ROLES, true)) {
            return ['ok' => false, 'status' => 401, 'message' => 'Invalid credentials or insufficient access.'];
        }

        try {
            $token = $this->tokens->encode(
                (int) $user['id'],
                (string) $user['role'],
                $this->organizationId,
                $this->ttl,
            );
        } catch (\RuntimeException $error) {
            return ['ok' => false, 'status' => 503, 'message' => $error->getMessage()];
        }

        return [
            'ok' => true,
            'status' => 200,
            'token' => $token['token'],
            'token_type' => 'Bearer',
            'expires_at' => $token['expires_at'],
            'user' => [
                'id' => (int) $user['id'],
                'name' => (string) ($user['full_name'] ?? ''),
                'role' => (string) $user['role'],
            ],
        ];
    }
}
