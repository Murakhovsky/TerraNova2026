<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class InventoryTransactionType
{
    public const SALE = 'sale';
    public const RENT = 'rent';
    public const INVESTMENT = 'investment';

    private const VALUES = [self::SALE, self::RENT, self::INVESTMENT];

    private function __construct(public string $value) {}

    public static function from(string $value): self
    {
        $value = strtolower(trim($value));
        if (!in_array($value, self::VALUES, true)) {
            throw new InvalidArgumentException('Unsupported InventoryTransactionType: ' . $value);
        }
        return new self($value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return self::VALUES;
    }
}
