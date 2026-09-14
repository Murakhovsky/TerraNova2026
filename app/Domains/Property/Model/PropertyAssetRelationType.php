<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class PropertyAssetRelationType
{
    public const CONTAINS = 'contains';
    public const PART_OF = 'part_of';
    public const LOCATED_IN = 'located_in';
    public const BUILT_ON = 'built_on';
    public const SERVES = 'serves';
    public const ADJACENT_TO = 'adjacent_to';

    private const VALUES = [
        self::CONTAINS,
        self::PART_OF,
        self::LOCATED_IN,
        self::BUILT_ON,
        self::SERVES,
        self::ADJACENT_TO,
    ];

    public function __construct(public string $value)
    {
        if (!in_array($this->value, self::VALUES, true)) {
            throw new InvalidArgumentException('Unsupported PropertyAsset relation type: ' . $this->value);
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
