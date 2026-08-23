<?php
declare(strict_types=1);

namespace Kernel\Policy\Service;

use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;
use Kernel\Rule\Service\ConditionEvaluator;

final readonly class PolicyEngine
{
    public function __construct(private ConditionEvaluator $conditionEvaluator)
    {
    }

    /** @param iterable<ActionPolicy> $policies */
    public function decide(string $actionType, array $context, iterable $policies): PolicyDecision
    {
        $matching = [];
        foreach ($policies as $policy) {
            if (($policy->actionType === $actionType || $policy->actionType === '*')
                && $this->conditionEvaluator->matches($policy->conditions, $context)) {
                $matching[] = $policy;
            }
        }

        usort($matching, static fn (ActionPolicy $left, ActionPolicy $right): int => $left->priority <=> $right->priority);
        return $matching[0]->decision ?? PolicyDecision::Denied;
    }
}
