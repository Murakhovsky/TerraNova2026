<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Portfolio;
enum HedgeState:string
{
    case Planned='PLANNED';
    case Building='BUILDING';
    case PartiallyHedged='PARTIALLY_HEDGED';
    case Hedged='HEDGED';
    case Drifted='DRIFTED';
    case RehedgeRequired='REHEDGE_REQUIRED';
    case Unwinding='UNWINDING';
    case Closed='CLOSED';
    case Failed='FAILED';
}
