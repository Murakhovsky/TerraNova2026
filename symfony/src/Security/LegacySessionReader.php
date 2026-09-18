<?php

declare(strict_types=1);

namespace App\Security;

final class LegacySessionReader
{
    public function __construct(
        private readonly string $savePath,
        private readonly string $cookieName,
    ) {
    }

    public function cookieName(): string
    {
        return $this->cookieName;
    }

    /** @return array{user_id:int, organization_id:?string}|null */
    public function read(string $sessionId): ?array
    {
        $payload = $this->payload($sessionId);
        if ($payload === null) {
            return null;
        }

        $userId = null;
        if (preg_match('/(?:^|[;}])tn_auth_user_id\|i:(\d+);/', $payload, $match) === 1) {
            $userId = (int) $match[1];
        } elseif (preg_match('/(?:^|[;}])tn_auth_user_id\|s:\d+:"(\d+)";/', $payload, $match) === 1) {
            $userId = (int) $match[1];
        }

        if ($userId === null || $userId <= 0) {
            return null;
        }

        $organizationId = null;
        if (preg_match('/(?:^|[;}])cos_organization_id\|s:(\d+):"([A-Za-z0-9._:-]*)";/', $payload, $match) === 1) {
            $candidate = $match[2];
            if ((int) $match[1] === strlen($candidate) && $candidate !== '') {
                $organizationId = $candidate;
            }
        }

        return [
            'user_id' => $userId,
            'organization_id' => $organizationId,
        ];
    }

    public function csrfToken(string $sessionId): ?string
    {
        $payload = $this->payload($sessionId);
        if ($payload === null) {
            return null;
        }

        if (preg_match('/(?:^|[;}])cos_csrf_token\|s:(\d+):"([A-Fa-f0-9]{32,128})";/', $payload, $match) !== 1) {
            return null;
        }

        $token = $match[2];
        return (int) $match[1] === strlen($token) ? $token : null;
    }

    private function payload(string $sessionId): ?string
    {
        if (!preg_match('/^[A-Za-z0-9,-]{16,128}$/', $sessionId)) {
            return null;
        }

        $path = rtrim($this->savePath, '/') . '/sess_' . $sessionId;
        if (!is_file($path)) {
            return null;
        }

        $size = filesize($path);
        if ($size === false || $size < 1 || $size > 1048576) {
            return null;
        }

        $payload = file_get_contents($path);
        return is_string($payload) && $payload !== '' ? $payload : null;
    }
}
