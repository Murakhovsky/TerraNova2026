<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final readonly class SpatialJwtCodec
{
    private const ISSUER = 'terra-nova-spatial';

    public function __construct(private string $secret)
    {
    }

    public function encode(int $userId, string $role, string $organizationId, int $ttl): array
    {
        if ($this->secret === '') {
            throw new RuntimeException('Spatial JWT secret is not configured.');
        }

        $now = time();
        $expires = $now + max(900, min(86400, $ttl));
        $payload = [
            'iss' => self::ISSUER,
            'sub' => (string) $userId,
            'role' => $role,
            'org' => $organizationId,
            'iat' => $now,
            'exp' => $expires,
        ];

        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $header . '.' . $body, $this->secret, true));

        return [
            'token' => $header . '.' . $body . '.' . $signature,
            'expires_at' => gmdate(DATE_ATOM, $expires),
        ];
    }

    /** @return array{sub:int,role:string,org:string,exp:int}|null */
    public function decode(string $token, string $fallbackOrganizationId): ?array
    {
        if ($this->secret === '') {
            return null;
        }

        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $body, $signature] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $header . '.' . $body, $this->secret, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        try {
            $payload = json_decode($this->base64UrlDecode($body), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($payload)
            || ($payload['iss'] ?? null) !== self::ISSUER
            || !ctype_digit((string) ($payload['sub'] ?? ''))
            || (int) $payload['sub'] <= 0
            || (int) ($payload['exp'] ?? 0) <= time()) {
            return null;
        }

        $organizationId = trim((string) ($payload['org'] ?? $fallbackOrganizationId));
        if ($organizationId === '' || preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $organizationId) !== 1) {
            return null;
        }

        return [
            'sub' => (int) $payload['sub'],
            'role' => (string) ($payload['role'] ?? ''),
            'org' => $organizationId,
            'exp' => (int) $payload['exp'],
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid JWT payload.');
        }
        return $decoded;
    }
}
