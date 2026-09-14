<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Registry;

use Domains\Diagnostic\Methodology\Model\MetricDefinition;
use InvalidArgumentException;

final class MetricRegistry
{
    /** @var array<string,MetricDefinition> */
    private array $metrics = [];

    /** @param iterable<MetricDefinition> $metrics */
    public function __construct(iterable $metrics)
    {
        foreach ($metrics as $metric) {
            if (isset($this->metrics[$metric->id])) {
                throw new InvalidArgumentException('Duplicate metric id: ' . $metric->id);
            }
            $this->metrics[$metric->id] = $metric;
        }
    }

    public function has(string $id): bool { return isset($this->metrics[$id]); }

    public function get(string $id): MetricDefinition
    {
        return $this->metrics[$id] ?? throw new InvalidArgumentException('Unknown metric: ' . $id);
    }

    /** @return list<MetricDefinition> */
    public function all(): array { return array_values($this->metrics); }
}
