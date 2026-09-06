<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Engine;

use Domains\Diagnostic\Methodology\Input\DiagnosticInput;
use InvalidArgumentException;

final class ApplicabilityEngine
{
    public function applies(array $condition, DiagnosticInput $input): bool
    {
        if ($condition === []) return true;
        if (isset($condition['all'])) {foreach ($condition['all'] as $child) if (!$this->applies($child, $input)) return false; return true;}
        if (isset($condition['any'])) {foreach ($condition['any'] as $child) if ($this->applies($child, $input)) return true; return false;}
        if (isset($condition['not'])) return !$this->applies($condition['not'], $input);
        $value = $input->get((string) ($condition['subject'] ?? $condition['field'] ?? ''));
        $operator = strtolower((string) ($condition['operator'] ?? 'eq'));
        if ($operator === 'exists') return $value !== null;
        if ($operator === 'missing' || $operator === 'not_exists') return $value === null;
        if ($value === null) return false;
        $expected = $condition['value'] ?? $condition['values'] ?? null; $actual = $value->value;
        return match ($operator) {
            '=', '==', 'eq' => $actual === $expected, '!=', 'neq' => $actual !== $expected,
            '>', 'gt' => $actual > $expected, '>=', 'gte' => $actual >= $expected,
            '<', 'lt' => $actual < $expected, '<=', 'lte' => $actual <= $expected,
            'in' => is_array($expected) && in_array($actual, $expected, true),
            'not_in' => is_array($expected) && !in_array($actual, $expected, true),
            'between' => is_array($expected) && count($expected) === 2 && $actual >= $expected[0] && $actual <= $expected[1],
            default => throw new InvalidArgumentException('Unsupported applicability operator: ' . $operator),
        };
    }
}
