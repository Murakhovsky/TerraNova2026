<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class PropertyVerificationStatus
{
    public const UNVERIFIED = 'unverified';
    public const INFERRED = 'inferred';
    public const CONFIRMED = 'confirmed';
    public const VERIFIED = 'verified';
    public const CONFLICTED = 'conflicted';
    public const REJECTED = 'rejected';

    private const VALUES = [
        self::UNVERIFIED,
        self::INFERRED,
        self::CONFIRMED,
        self::VERIFIED,
        self::CONFLICTED,
        self::REJECTED,
    ];

    public function __construct(public string $value)
    {
        if (!in_array($this->value, self::VALUES, true)) {
            throw new InvalidArgumentException('Unsupported Property verification status: ' . $this->value);
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
