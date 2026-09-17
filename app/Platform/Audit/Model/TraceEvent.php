<?php
declare(strict_types=1);

namespace Platform\Audit\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TraceEvent
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public int $sequence,
        public TraceEventType $type,
        public array $payload,
        public ActivityStatus $status,
        public DateTimeImmutable $timestamp,
        public ?int $durationMs = null,
        public ?float $cost = null,
        public ?string $costUnit = null,
        public ?string $error = null,
    ) {
        if ($this->sequence < 1 || ($this->durationMs !== null && $this->durationMs < 0) || ($this->cost !== null && $this->cost < 0)) {
            throw new InvalidArgumentException('Trace event sequence/duration/cost is invalid.');
        }
        if ($this->cost !== null && ($this->costUnit === null || trim($this->costUnit) === '')) {
            throw new InvalidArgumentException('Trace event cost requires a unit.');
        }
    }
}
