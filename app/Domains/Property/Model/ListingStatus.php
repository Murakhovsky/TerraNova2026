<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class ListingStatus
{
    public const DRAFT = 'draft';
    public const READY = 'ready';
    public const PUBLISHED = 'published';
    public const HIDDEN = 'hidden';
    public const EXPIRED = 'expired';
    public const ARCHIVED = 'archived';

    private const VALUES = [self::DRAFT, self::READY, self::PUBLISHED, self::HIDDEN, self::EXPIRED, self::ARCHIVED];

    private function __construct(public string $value) {}

    public static function from(string $value): self
    {
        $value = strtolower(trim($value));
        if (!in_array($value, self::VALUES, true)) throw new InvalidArgumentException('Unsupported ListingStatus: ' . $value);
        return new self($value);
    }

    /** @return list<string> */
    public static function values(): array { return self::VALUES; }
}
