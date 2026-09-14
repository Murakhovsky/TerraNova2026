<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final class PropertyMarketPosition
{
    public const UNDERPRICED = 'UNDERPRICED';
    public const FAIR = 'FAIR';
    public const OVERPRICED = 'OVERPRICED';
    public const UNKNOWN = 'UNKNOWN';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::UNDERPRICED, self::FAIR, self::OVERPRICED, self::UNKNOWN];
    }

    public static function normalize(string $value): string
    {
        $value = strtoupper(trim($value));
        if (!in_array($value, self::values(), true)) {
            throw new InvalidArgumentException('Unsupported Property market position: ' . $value);
        }
        return $value;
    }
}
