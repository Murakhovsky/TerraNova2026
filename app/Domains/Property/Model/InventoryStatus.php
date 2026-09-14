<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class InventoryStatus
{
    public const AVAILABLE = 'available';
    public const RESERVED = 'reserved';
    public const ON_HOLD = 'on_hold';
    public const UNDER_OFFER = 'under_offer';
    public const SOLD = 'sold';
    public const RENTED = 'rented';
    public const OFF_MARKET = 'off_market';
    public const WITHDRAWN = 'withdrawn';

    private const VALUES = [
        self::AVAILABLE,
        self::RESERVED,
        self::ON_HOLD,
        self::UNDER_OFFER,
        self::SOLD,
        self::RENTED,
        self::OFF_MARKET,
        self::WITHDRAWN,
    ];

    private function __construct(public string $value) {}

    public static function from(string $value): self
    {
        $value = strtolower(trim($value));
        if (!in_array($value, self::VALUES, true)) {
            throw new InvalidArgumentException('Unsupported InventoryStatus: ' . $value);
        }
        return new self($value);
    }

    public static function available(): self
    {
        return new self(self::AVAILABLE);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return self::VALUES;
    }

    public function isMarketable(): bool
    {
        return in_array($this->value, [self::AVAILABLE, self::UNDER_OFFER], true);
    }

    public function isClosed(): bool
    {
        return in_array($this->value, [self::SOLD, self::RENTED, self::WITHDRAWN], true);
    }
}
