<?php
declare(strict_types=1);

namespace Kernel\Rule\Service;

use Kernel\Rule\Rule;

final readonly class RuleEngine
{
    public function __construct(private ConditionEvaluator $conditionEvaluator)
    {
    }

    /** @param iterable<Rule> $rules @return list<Rule> */
    public function matching(string $eventType, array $context, iterable $rules): array
    {
        $matched = [];
        foreach ($rules as $rule) {
            if ($rule->trigger === $eventType && $this->conditionEvaluator->matches($rule->conditions, $context)) {
                $matched[] = $rule;
            }
        }

        usort($matched, static fn (Rule $left, Rule $right): int => $left->priority <=> $right->priority);
        return $matched;
    }
}
