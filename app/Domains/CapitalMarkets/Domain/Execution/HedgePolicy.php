<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Execution;
enum HedgePolicy:string
{
    case MaintainDeltaNeutral='MAINTAIN_DELTA_NEUTRAL';
    case CompleteMissingLeg='COMPLETE_MISSING_LEG';
    case ReduceFilledLeg='REDUCE_FILLED_LEG';
    case AlternateVenue='ALTERNATE_VENUE';
    case EmergencyFlatten='EMERGENCY_FLATTEN';
}
