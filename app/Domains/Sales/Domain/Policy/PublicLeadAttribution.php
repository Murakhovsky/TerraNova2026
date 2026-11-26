<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Policy;

/** Untrusted public UTM observations, never an assigned campaign or customer identity. */
final class PublicLeadAttribution
{
    /** Only a complete, bounded canonical tuple is eligible for persistence. */
    public static function observe(array $input): array
    {
        $tags = [];
        foreach ([
            'utm_source' => '/^[a-z0-9][a-z0-9_.-]{0,63}$/D',
            'utm_medium' => '/^[a-z0-9][a-z0-9_.-]{0,63}$/D',
            'utm_campaign' => '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,159}$/D',
        ] as $field => $pattern) {
            $value = $input[$field] ?? null;
            if (!is_string($value) || preg_match($pattern, $value) !== 1) return [];
            $tags[$field] = $value;
        }
        return $tags;
    }
}
