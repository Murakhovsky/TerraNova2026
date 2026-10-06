<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Execution;

enum CompensationPolicy:string
{
    case RetrySecondLeg='RETRY_SECOND_LEG';
    case UseAlternativeMarket='USE_ALTERNATIVE_MARKET';
    case ReduceFirstLeg='REDUCE_FIRST_LEG';
    case EmergencyClose='EMERGENCY_CLOSE';
    case MarkManualIntervention='MARK_MANUAL_INTERVENTION';

    public function supportedInVerticalSliceV1():bool
    {
        return in_array($this,[self::RetrySecondLeg,self::EmergencyClose],true);
    }
}
