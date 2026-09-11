<?php
declare(strict_types=1);

namespace Kernel\Policy\Service;

use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;
use Kernel\Policy\PolicyEvaluation;
use Kernel\Rule\Service\ConditionEvaluator;

final readonly class PolicyEngine
{
    public function __construct(private ConditionEvaluator $conditionEvaluator) {}
    public function decide(string $actionType,array $context,iterable $policies): PolicyDecision{return $this->evaluate($actionType,$context,$policies)->decision;}
    public function evaluate(string $actionType,array $context,iterable $policies): PolicyEvaluation
    {
        $matching=[];foreach($policies as $policy){if(($policy->actionType===$actionType||$policy->actionType==='*')&&$this->conditionEvaluator->matches($policy->conditions,$context))$matching[]=$policy;}
        usort($matching,static fn(ActionPolicy $a,ActionPolicy $b):int=>$a->priority<=>$b->priority);$policy=$matching[0]??null;
        $reason=$policy===null?'No active policy matched; deny by default.':($policy->reason?:sprintf('Matched policy %s.', $policy->name ?: $policy->id));
        return new PolicyEvaluation($policy?->decision??PolicyDecision::Denied,$policy,$reason);
    }
}
