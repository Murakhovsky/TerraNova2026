<?php
declare(strict_types=1);

namespace Kernel\Workflow\Service;

use Kernel\Workflow\Contract\ConditionEvaluatorInterface;
use Kernel\Workflow\Model\Condition;
use Kernel\Workflow\Model\ConditionOperator;

final class PathConditionEvaluator implements ConditionEvaluatorInterface
{
    public function matches(Condition $condition, array $context): bool
    {
        [$exists, $actual] = $this->read($context, $condition->path);

        return match ($condition->operator) {
            ConditionOperator::EXISTS => $exists,
            ConditionOperator::TRUTHY => $exists && (bool) $actual,
            ConditionOperator::EQ => $exists && $actual === $condition->value,
            ConditionOperator::NE => !$exists || $actual !== $condition->value,
            ConditionOperator::GT => $exists && $actual > $condition->value,
            ConditionOperator::GTE => $exists && $actual >= $condition->value,
            ConditionOperator::LT => $exists && $actual < $condition->value,
            ConditionOperator::LTE => $exists && $actual <= $condition->value,
        };
    }

    /** @param array<string,mixed> $context @return array{bool,mixed} */
    private function read(array $context, string $path): array
    {
        $value = $context;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) return [false, null];
            $value = $value[$segment];
        }
        return [true, $value];
    }
}
