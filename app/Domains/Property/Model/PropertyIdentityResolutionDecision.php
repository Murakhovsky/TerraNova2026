<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class PropertyIdentityResolutionDecision
{
    public const CREATE = 'create';
    public const MERGE = 'merge';
    public const REVIEW = 'review';
    public const REJECT = 'reject';

    private const VALUES = [self::CREATE, self::MERGE, self::REVIEW, self::REJECT];

    public function __construct(public string $value)
    {
        if (!in_array($this->value, self::VALUES, true)) {
            throw new InvalidArgumentException('Unsupported Property identity resolution decision: ' . $this->value);
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
