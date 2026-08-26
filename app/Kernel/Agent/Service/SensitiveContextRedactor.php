<?php
declare(strict_types=1);

namespace Kernel\Agent\Service;

use Kernel\Agent\Contract\ContextRedactorInterface;

final class SensitiveContextRedactor implements ContextRedactorInterface
{
    private const SENSITIVE_KEYS = [
        'password', 'password_hash', 'token', 'secret', 'authorization', 'cookie', 'api_key',
        'credit_card', 'card_number', 'iban', 'passport', 'tax_id',
    ];

    public function redact(array $context): array
    {
        return $this->walk($context, 0);
    }

    private function walk(array $data, int $depth): array
    {
        if ($depth > 12) return ['_truncated' => true];
        foreach ($data as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
                $data[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->walk($value, $depth + 1);
                continue;
            }
            if (is_string($value)) {
                if (str_contains($normalized, 'email')) {
                    $data[$key] = $this->maskEmail($value);
                } elseif (str_contains($normalized, 'phone') || str_contains($normalized, 'telegram')) {
                    $data[$key] = $this->maskContact($value);
                } else {
                    $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[EMAIL]', $value) ?? $value;
                    $value = preg_replace('/(?<!\d)\+?\d[\d\s().-]{7,}\d(?!\d)/', '[PHONE]', $value) ?? $value;
                    $data[$key] = mb_substr($value, 0, 12000);
                }
            }
        }
        return $data;
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) return '[EMAIL]';
        [, $domain] = explode('@', $email, 2);
        return '[EMAIL]@' . mb_substr($domain, 0, 120);
    }

    private function maskContact(string $value): string
    {
        $tail = preg_replace('/\D+/', '', $value) ?? '';
        return $tail === '' ? '[CONTACT]' : '[CONTACT]…' . substr($tail, -2);
    }
}
