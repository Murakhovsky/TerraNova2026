<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\Portfolio\HedgeGroup;
use Domains\CapitalMarkets\Domain\Portfolio\HedgeState;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final class HedgeMonitor
{
    /** @return array{state:HedgeState,net_delta:string,target_delta:string,drift:string,tolerance:string,rehedge_required:bool} */
    public function inspect(HedgeGroup $group):array
    {
        $net=$group->netUnderlyingExposure();
        $drift=$group->drift();
        $required=DecimalMath::abs($drift)->compareTo($group->allowedTolerance)>0;
        return [
            'state'=>$required?HedgeState::RehedgeRequired:HedgeState::Hedged,
            'net_delta'=>$net->value(),
            'target_delta'=>$group->targetDelta->value(),
            'drift'=>$drift->value(),
            'tolerance'=>$group->allowedTolerance->value(),
            'rehedge_required'=>$required,
        ];
    }
}
