<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class LocationNodeType
{
    public const COUNTRY = 'country';
    public const REGION = 'region';
    public const DISTRICT = 'district';
    public const CITY = 'city';
    public const SETTLEMENT = 'settlement';
    public const CITY_DISTRICT = 'city_district';
    public const STREET = 'street';

    private const VALUES = [
        self::COUNTRY,
        self::REGION,
        self::DISTRICT,
        self::CITY,
        self::SETTLEMENT,
        self::CITY_DISTRICT,
        self::STREET,
    ];

    public function __construct(public string $value)
    {
        if (!in_array($this->value, self::VALUES, true)) {
            throw new InvalidArgumentException('Unsupported location node type: ' . $this->value);
        }
    }

    public static function from(string $value): self
    {
        return new self(strtolower(trim($value)));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return self::VALUES;
    }
}
