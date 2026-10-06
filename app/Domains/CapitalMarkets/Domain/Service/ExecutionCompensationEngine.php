<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\Execution\CompensationDecision;
use Domains\CapitalMarkets\Domain\Execution\CompensationPolicy;
use Domains\CapitalMarkets\Domain\Execution\ExecutionGroupState;
use Domains\CapitalMarkets\Domain\Execution\ExecutionLegResult;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final class ExecutionCompensationEngine
{
    public function decide(
        ExecutionLegResult $firstLeg,
        ExecutionLegResult $secondLeg,
        CompensationPolicy $preferredPolicy,
    ):CompensationDecision{
        $matched=$firstLeg->filledQuantity->compareTo($secondLeg->filledQuantity)<=0
            ?$firstLeg->filledQuantity:$secondLeg->filledQuantity;
        $unhedged=DecimalMath::subtract($firstLeg->filledQuantity,$matched);
        if($unhedged->isNegative())$unhedged=Decimal::fromString('0');

        if($firstLeg->fullyFilled()&&$secondLeg->fullyFilled()){
            return new CompensationDecision(ExecutionGroupState::Completed,null,Decimal::fromString('0'),'BOTH_LEGS_FILLED');
        }

        if(!$firstLeg->filledQuantity->isPositive()&&!$secondLeg->filledQuantity->isPositive()){
            return new CompensationDecision(ExecutionGroupState::Failed,null,Decimal::fromString('0'),'NO_LEG_FILLED');
        }

        if(!$unhedged->isPositive()){
            return new CompensationDecision(ExecutionGroupState::PartiallyExecuted,null,Decimal::fromString('0'),'MATCHED_PARTIAL_FILL');
        }

        $policy=$preferredPolicy->supportedInVerticalSliceV1()
            ?$preferredPolicy
            :CompensationPolicy::EmergencyClose;

        return new CompensationDecision(
            ExecutionGroupState::Compensating,
            $policy,
            $unhedged,
            'UNHEDGED_FIRST_LEG_EXPOSURE',
        );
    }
}
