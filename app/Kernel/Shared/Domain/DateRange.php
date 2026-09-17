<?php
declare(strict_types=1);

namespace Kernel\Shared\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DateRange extends ValueObject
{
    public function __construct(
        private DateTimeImmutable $start,
        private DateTimeImmutable $end,
    ) {
        if ($end < $start) {
            throw new InvalidArgumentException('Date range end must not be before start.');
        }
    }

    public function start(): DateTimeImmutable
    {
        return $this->start;
    }

    public function end(): DateTimeImmutable
    {
        return $this->end;
    }

    public function contains(DateTimeImmutable $moment): bool
    {
        return $moment >= $this->start && $moment <= $this->end;
    }
}
