<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Engine;

use Domains\Diagnostic\Methodology\Input\DiagnosticInput;
use Domains\Diagnostic\Methodology\Model\CriterionDefinition;
use Domains\Diagnostic\Methodology\Result\Coverage;

final class CoverageEngine
{
    public function evaluate(CriterionDefinition $criterion, DiagnosticInput $input): Coverage
    {
        if ($criterion->required === []) return new Coverage(1.0, 'COMPLETE');
        $missing = array_values(array_filter($criterion->required, static fn (string $id): bool => $input->get($id) === null));
        $totalWeight = array_sum(array_map($criterion->requiredWeight(...), $criterion->required));
        $missingWeight = array_sum(array_map($criterion->requiredWeight(...), $missing));
        $ratio = $totalWeight > 0 ? ($totalWeight - $missingWeight) / $totalWeight : 0.0;
        return new Coverage($ratio, $this->level($ratio), $missing);
    }

    public function level(float $ratio): string
    {
        return match (true) {
            $ratio <= 0.0 => 'NONE',
            $ratio < 0.5 => 'LOW',
            $ratio < 0.8 => 'MEDIUM',
            $ratio < 1.0 => 'HIGH',
            default => 'COMPLETE',
        };
    }
}
