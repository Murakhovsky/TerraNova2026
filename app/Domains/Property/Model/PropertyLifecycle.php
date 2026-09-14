<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class PropertyLifecycle
{
    public const UNKNOWN = 'unknown';
    public const PLANNED = 'planned';
    public const UNDER_CONSTRUCTION = 'under_construction';
    public const COMPLETED = 'completed';
    public const RENOVATION = 'renovation';
    public const DAMAGED = 'damaged';
    public const DEMOLISHED = 'demolished';

    private const VALUES = [
        self::UNKNOWN,
        self::PLANNED,
        self::UNDER_CONSTRUCTION,
        self::COMPLETED,
        self::RENOVATION,
        self::DAMAGED,
        self::DEMOLISHED,
    ];

    private function __construct(public string $value)
    {
    }

    public static function from(string $value): self
    {
        $value = trim($value);
        if (!in_array($value, self::VALUES, true)) {
            throw new InvalidArgumentException('Unsupported PropertyLifecycle: ' . $value);
        }

        return new self($value);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return self::VALUES;
    }

    public function isTerminal(): bool
    {
        return $this->value === self::DEMOLISHED;
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this->value === $next->value) {
            return true;
        }

        return !$this->isTerminal();
    }
}
