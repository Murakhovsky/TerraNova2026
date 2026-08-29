<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

trait HasStringValues
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function accepts(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }
}
