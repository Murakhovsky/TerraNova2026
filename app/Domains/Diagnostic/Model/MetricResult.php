<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MetricResult
{
    /** @param list<string> $upstreamIds */
    public function __construct(
        public string $id,
        public string $metricId,
        public int|float $value,
        public ?string $unit,
        public float $confidence,
        public array $upstreamIds,
        public string $definitionId,
        public DateTimeImmutable $computedAt,
    ) {
        if ($id === '' || $metricId === '' || $definitionId === '' || $confidence < 0 || $confidence > 1 || $upstreamIds === []) {
            throw new InvalidArgumentException('Invalid metric result.');
        }
        if (count(array_unique($upstreamIds)) !== count($upstreamIds)) {
            throw new InvalidArgumentException('Metric upstream references must be unique.');
        }
    }
}
