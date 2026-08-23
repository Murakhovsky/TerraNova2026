<?php
declare(strict_types=1);

namespace Kernel\Rule\Service;

use InvalidArgumentException;

final class ConditionEvaluator
{
    public function matches(array $conditions, array $context): bool
    {
        if (array_key_exists('all', $conditions)) {
            return is_array($conditions['all']) && $this->matches($conditions['all'], $context);
        }
        if (array_key_exists('any', $conditions)) {
            if (!is_array($conditions['any'])) {
                return false;
            }
            foreach ($conditions['any'] as $condition) {
                if ($this->matches([$condition], $context)) {
                    return true;
                }
            }
            return false;
        }
        if (array_key_exists('not', $conditions)) {
            return is_array($conditions['not']) && !$this->matches([$conditions['not']], $context);
        }

        foreach ($conditions as $condition) {
            if (isset($condition['all']) || isset($condition['any']) || isset($condition['not'])) {
                if (!$this->matches($condition, $context)) {
                    return false;
                }
                continue;
            }

            $exists = false;
            $actual = $this->resolve($context, (string) ($condition['field'] ?? ''), $exists);
            $operator = strtoupper((string) ($condition['operator'] ?? '='));
            $expected = $condition['value'] ?? null;

            $matched = match ($operator) {
                '=', 'EQ' => $actual === $expected,
                '!=', 'NEQ' => $actual !== $expected,
                '>', 'GT' => $actual > $expected,
                '>=', 'GTE' => $actual >= $expected,
                '<', 'LT' => $actual < $expected,
                '<=', 'LTE' => $actual <= $expected,
                'IN' => is_array($expected) && in_array($actual, $expected, true),
                'NOT_IN' => is_array($expected) && !in_array($actual, $expected, true),
                'IS_NULL' => $actual === null,
                'IS_NOT_NULL' => $actual !== null,
                'EXISTS' => $exists,
                'NOT_EXISTS' => !$exists,
                'CONTAINS' => is_array($actual)
                    ? in_array($expected, $actual, true)
                    : (is_string($actual) && is_string($expected) && str_contains($actual, $expected)),
                default => throw new InvalidArgumentException(sprintf('Unsupported rule operator: %s', $operator)),
            };

            if (!$exists && !in_array($operator, ['EXISTS', 'NOT_EXISTS'], true)) {
                $matched = false;
            }

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    private function resolve(array $context, string $path, bool &$exists): mixed
    {
        $exists = false;
        $value = $context;
        foreach (explode('.', $path) as $segment) {
            if ($segment === '' || !is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        $exists = true;

        return $value;
    }
}
