<?php
declare(strict_types=1);

namespace Modules\Spatial\Services;

use Common\Services\AuthService;
use Common\Services\DatabaseService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

class SpatialAccessService
{
    private const EDIT_ROLES = ['admin', 'manager', 'realtor', 'partner', 'developer'];

    public function __construct(
        private DatabaseService $database,
        private AuthService $auth,
        private string $jwtSecret,
        private int $jwtTtl = 28800
    ) {
    }

    public function token(array $credentials): array
    {
        if ($this->jwtSecret === '') {
            return ['ok' => false, 'status' => 503, 'message' => 'Spatial JWT secret is not configured.'];
        }
        $result = $this->auth->login($credentials);
        $user = !empty($result['ok']) ? $this->auth->currentUser() : null;
        if (!$user || !$this->canEdit($user)) {
            return ['ok' => false, 'status' => 401, 'message' => 'Invalid credentials or insufficient access.'];
        }
        $now = time();
        $expires = $now + max(900, min(86400, $this->jwtTtl));
        $token = JWT::encode([
            'iss' => 'terra-nova-spatial',
            'sub' => (string) $user['id'],
            'role' => (string) $user['role'],
            'iat' => $now,
            'exp' => $expires,
        ], $this->jwtSecret, 'HS256');

        return [
            'ok' => true,
            'status' => 200,
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => gmdate(DATE_ATOM, $expires),
            'user' => $this->publicUser($user),
        ];
    }

    public function actor(string $authorization = ''): ?array
    {
        $sessionUser = $this->auth->currentUser();
        if ($sessionUser) {
            return $sessionUser;
        }
        if ($this->jwtSecret === '' || !preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches)) {
            return null;
        }
        try {
            $claims = JWT::decode(trim($matches[1]), new Key($this->jwtSecret, 'HS256'));
            if (($claims->iss ?? '') !== 'terra-nova-spatial' || (int) ($claims->sub ?? 0) <= 0) {
                return null;
            }
            return $this->database->fetchOne('
                SELECT id, email, full_name, phone, role, status, last_login_at, created_at
                FROM tn_users WHERE id = :id AND status = "active" LIMIT 1
            ', ['id' => (int) $claims->sub]);
        } catch (Throwable) {
            return null;
        }
    }

    public function editor(string $authorization = ''): ?array
    {
        $actor = $this->actor($authorization);
        return $actor && $this->canEdit($actor) ? $actor : null;
    }

    public function canEdit(?array $user): bool
    {
        return $user && in_array((string) ($user['role'] ?? ''), self::EDIT_ROLES, true);
    }

    private function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => (string) $user['full_name'],
            'role' => (string) $user['role'],
        ];
    }
}
