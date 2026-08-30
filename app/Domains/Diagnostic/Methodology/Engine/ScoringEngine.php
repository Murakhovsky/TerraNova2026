<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Engine;

use Domains\Diagnostic\Methodology\Input\DiagnosticInput;
use Domains\Diagnostic\Methodology\Model\CriterionDefinition;
use Domains\Diagnostic\Methodology\Model\ScoringDefinition;
use Domains\Diagnostic\Methodology\Model\SectionDefinition;

final class ScoringEngine
{
    /** @param iterable<ScoringDefinition> $definitions */
    public function criterionScore(string $criterionId, DiagnosticInput $input, iterable $definitions): ?float
    {
        $scores = []; $aggregation = 'weighted_average';
        foreach ($definitions as $definition) {
            if ($definition->criterionId !== $criterionId) continue;
            $observed = $input->get('metric.' . $definition->metricId);
            if ($observed === null || !is_numeric($observed->value)) continue;
            $score = $this->normalizedScore((float) $observed->value, $definition);
            if ($score === null) continue;
            $score = max($definition->minimum, min($definition->maximum, $score - $definition->penalty + $definition->bonus));
            $scores[] = ['score' => $score, 'weight' => $definition->weight];
            $aggregation = $definition->aggregation;
        }
        if ($scores === []) return null;
        $values = array_column($scores, 'score');
        $weights = array_sum(array_column($scores, 'weight'));
        $result = match ($aggregation) {
            'average' => array_sum($values) / count($values),
            'sum' => array_sum(array_map(static fn (array $item): float => $item['score'] * $item['weight'], $scores)),
            'minimum' => min($values),
            'maximum' => max($values),
            default => $weights > 0 ? array_sum(array_map(static fn (array $item): float => $item['score'] * $item['weight'], $scores)) / $weights : null,
        };
        return $result !== null ? round(max(0.0, min(100.0, $result)), 2) : null;
    }

    /** @param array<string,float|null> $criterionScores @param list<CriterionDefinition> $criteria @param list<SectionDefinition> $sections @return array<string,float|null> */
    public function sectionScores(array $criterionScores, array $criteria, array $sections): array
    {
        $result = [];
        foreach ($sections as $section) {
            $sum = 0.0; $weights = 0.0;
            $blocked = false;
            foreach ($criteria as $criterion) {
                $score = $criterionScores[$criterion->id] ?? null;
                if ($criterion->sectionId !== $section->id) continue;
                if ($score === null) { $blocked = true; break; }
                $sum += $score * $criterion->weight; $weights += $criterion->weight;
            }
            $result[$section->id] = !$blocked && $weights > 0 ? round($sum / $weights, 2) : null;
        }
        return $result;
    }

    /** @param array<string,float|null> $sectionScores @param list<SectionDefinition> $sections */
    public function packScore(array $sectionScores, array $sections): ?float
    {
        $sum = 0.0; $weights = 0.0;
        foreach ($sections as $section) {
            $score = $sectionScores[$section->id] ?? null;
            if ($score === null) return null;
            $sum += $score * $section->weight; $weights += $section->weight;
        }
        return $weights > 0 ? round($sum / $weights, 2) : null;
    }

    private function normalizedScore(float $value, ScoringDefinition $definition): ?float
    {
        if ($definition->normalization !== 'bands') {
            $ratio = ($value - $definition->inputMinimum) / ($definition->inputMaximum - $definition->inputMinimum);
            $ratio = max(0.0, min(1.0, $ratio));
            if ($definition->normalization === 'inverse_linear') $ratio = 1.0 - $ratio;
            return $definition->minimum + ($ratio * ($definition->maximum - $definition->minimum));
        }
        foreach ($definition->bands as $band) {
            if ((!isset($band['min']) || $value >= $band['min']) && (!isset($band['max']) || $value < $band['max'])) return (float) $band['score'];
        }
        return null;
    }
}
