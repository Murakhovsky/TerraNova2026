<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Model;

final readonly class MetricDefinition
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public ?string $unit = null,
        public string $direction = 'neutral',
        public ?string $normalization = null,
        public ?array $expectedRange = null,
        public string $aggregation = 'average',
    ) {
    }
}
