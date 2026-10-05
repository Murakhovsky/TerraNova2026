<?php
declare(strict_types=1);

namespace App\Engineering\Application\Security;

final class EngineeringSecretIsolationGuard
{
    /** @var list<string> */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password','passwd','secret','token','authorization','cookie','api_key','apikey',
        'private_key','client_secret','access_token','refresh_token','credential_value',
    ];

    public function sanitize(array $context): array
    {
        $sanitized = $this->walk($context);
        return is_array($sanitized) ? $sanitized : [];
    }

    public function sanitizeText(string $value): string
    {
        $value = preg_replace('/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?-----END [A-Z0-9 ]*PRIVATE KEY-----/is', '[REDACTED_PRIVATE_KEY]', $value) ?? $value;
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace('/\b(?:ghp_|github_pat_|xox[baprs]-|sk-)[A-Za-z0-9_\-]{12,}/i', '[REDACTED_TOKEN]', $value) ?? $value;
        $value = preg_replace(
            '/\b(password|passwd|secret|token|api[_ -]?key|client[_ -]?secret|authorization)\b\s*[:=]\s*([^\s,;]+)/i',
            '$1=[REDACTED]',
            $value,
        ) ?? $value;
        return $value;
    }

    private function walk(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            if ($this->isReferenceKey($key) && is_string($value) && $this->isSecretReference($value)) {
                return trim($value);
            }
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $childKey => $childValue) {
                $out[$childKey] = $this->walk($childValue, is_string($childKey) ? $childKey : null);
            }
            return $out;
        }

        if (is_string($value)) return $this->sanitizeText($value);
        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-',' '], '_', trim($key)));
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if ($normalized === $fragment || str_contains($normalized, $fragment)) return true;
        }
        return false;
    }

    private function isReferenceKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-',' '], '_', trim($key)));
        return str_contains($normalized, 'reference')
            || str_ends_with($normalized, '_ref')
            || str_ends_with($normalized, '_uri');
    }

    private function isSecretReference(string $value): bool
    {
        return preg_match('#^(?:env|vault|secret)://[A-Za-z0-9_./:@\-]+$#', trim($value)) === 1;
    }
}
