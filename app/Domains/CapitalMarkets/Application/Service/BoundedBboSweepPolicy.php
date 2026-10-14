<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscription;
use Domains\CapitalMarkets\Domain\MarketData\RateLimitPolicy;
use InvalidArgumentException;

/** Pure admission policy; never truncates the target list or rounds quotas upward. */
final class BoundedBboSweepPolicy
{
    /**
     * @param list<MarketSubscription> $subscriptions
     * @return array{status:string,targets:int,request_budget:int}
     */
    public function evaluate(array $subscriptions,RateLimitPolicy $budget,int $maxTargets,int $cooldownSeconds):array
    {
        if ($maxTargets<1 || $maxTargets>10 || $cooldownSeconds<300 || $cooldownSeconds>86400) {
            throw new InvalidArgumentException('Invalid BBO quota policy scope.');
        }
        $unique=[];
        foreach ($subscriptions as $subscription) {
            if (!$subscription instanceof MarketSubscription) {
                throw new InvalidArgumentException('BBO quota policy requires typed subscriptions.');
            }
            if ($subscription->dataType!==MarketEventType::Bbo) {
                return ['status'=>'BLOCKED_MIXED_SUBSCRIPTIONS','targets'=>0,
                    'request_budget'=>$budget->requestBudget];
            }
            $unique[$subscription->instrumentId->value()]=true;
        }
        $count=count($unique);
        if ($count===0) return ['status'=>'NO_ACTIVE_BBO','targets'=>0,
            'request_budget'=>$budget->requestBudget];
        $windows=max(1,(int)ceil($budget->windowSeconds/$cooldownSeconds));
        if ($count>$maxTargets || 3*$count*$windows>$budget->requestBudget) {
            return ['status'=>'BLOCKED_BUDGET','targets'=>$count,
                'request_budget'=>$budget->requestBudget];
        }
        return ['status'=>'READY','targets'=>$count,'request_budget'=>$budget->requestBudget];
    }
}
