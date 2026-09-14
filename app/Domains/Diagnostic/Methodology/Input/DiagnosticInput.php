<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Input;

use DateTimeImmutable;

final readonly class DiagnosticInput
{
    /** @param array<string,ObservedValue> $facts @param array<string,ObservedValue> $metrics */
    public function __construct(
        public array $facts,
        public array $metrics,
        public ?DateTimeImmutable $evaluatedAt = null,
    ) {
    }

    public function get(string $reference): ?ObservedValue
    {
        if (str_starts_with($reference, 'fact.')) return $this->facts[substr($reference, 5)] ?? null;
        if (str_starts_with($reference, 'metric.')) return $this->metrics[substr($reference, 7)] ?? null;
        return $this->metrics[$reference] ?? null;
    }

    /** @param list<string> $references @return list<string> */
    public function evidenceIds(array $references): array
    {
        $ids = [];
        foreach ($references as $reference) {
            foreach ($this->get($reference)?->evidence ?? [] as $signal) $ids[$signal->id] = true;
        }
        return array_keys($ids);
    }
}
