<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Model;

final readonly class ScoringDefinition
{
    /** @param list<array{min?:float|int,max?:float|int,score:float|int}> $bands */
    public function __construct(
        public string $criterionId,
        public string $metricId,
        public array $bands,
        public float $weight = 1.0,
        public float $penalty = 0.0,
        public float $bonus = 0.0,
        public float $minimum = 0.0,
        public float $maximum = 100.0,
        public string $normalization = 'bands',
        public string $aggregation = 'weighted_average',
        public ?float $inputMinimum = null,
        public ?float $inputMaximum = null,
    ) {
    }
}
