<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Engine;

use Domains\Diagnostic\Methodology\Input\DiagnosticInput;
use Domains\Diagnostic\Methodology\Model\RuleDefinition;
use Domains\Diagnostic\Methodology\Result\CriterionAssessment;
use Domains\Diagnostic\Methodology\Result\Finding;
use InvalidArgumentException;

final class RuleEngine
{
    /** @param iterable<RuleDefinition> $rules @param array<string,CriterionAssessment> $assessments @return list<Finding> */
    public function evaluate(iterable $rules, DiagnosticInput $input, array $assessments = []): array
    {
        $findings = [];
        foreach ($rules as $rule) {
            if ($this->matches($rule->conditions, $input, $assessments)) {
                $findings[] = new Finding(
                    $rule->id,
                    $rule->criterionId,
                    $rule->finding,
                    $rule->severity,
                    $assessments[$rule->criterionId]->evidenceIds ?? [],
                );
            }
        }
        return $findings;
    }

    /** @param array<string,CriterionAssessment> $assessments */
    private function matches(array $node, DiagnosticInput $input, array $assessments): bool
    {
        if (array_key_exists('all', $node)) {
            foreach ($node['all'] as $child) if (!$this->matches($child, $input, $assessments)) return false;
            return true;
        }
        if (array_key_exists('any', $node)) {
            foreach ($node['any'] as $child) if ($this->matches($child, $input, $assessments)) return true;
            return false;
        }
        if (array_key_exists('not', $node)) return !$this->matches($node['not'], $input, $assessments);

        $subject = (string) ($node['subject'] ?? $node['field'] ?? '');
        $operator = strtolower((string) ($node['operator'] ?? '=='));
        $observed = null;
        $exists = false;
        if (str_starts_with($subject, 'assessment.') || str_starts_with($subject, 'criterion.')) {
            $parts = explode('.', $subject);
            $assessment = $assessments[$parts[1] ?? ''] ?? null;
            if ($assessment !== null && isset($parts[2])) {
                $observed = match ($parts[2]) { 'score' => $assessment->score, 'confidence' => $assessment->confidence, 'coverage' => $assessment->coverage->ratio, default => null };
                $exists = $observed !== null;
            }
        } else {
            $value = $input->get($subject);
            $exists = $value !== null;
            $observed = $value?->value;
        }
        if ($operator === 'exists') return $exists;
        if ($operator === 'not_exists') return !$exists;
        if (!$exists) return false;
        $expected = $node['value'] ?? $node['values'] ?? null;
        return match ($operator) {
            '=', '==', 'eq' => $observed === $expected,
            '!=', 'neq' => $observed !== $expected,
            '>', 'gt' => $observed > $expected,
            '>=', 'gte' => $observed >= $expected,
            '<', 'lt' => $observed < $expected,
            '<=', 'lte' => $observed <= $expected,
            'between' => is_array($expected) && count($expected) === 2 && $observed >= $expected[0] && $observed <= $expected[1],
            'in' => is_array($expected) && in_array($observed, $expected, true),
            default => throw new InvalidArgumentException('Unsupported methodology rule operator: ' . $operator),
        };
    }
}
