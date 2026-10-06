<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Strategy;

enum ExitDecision:string
{
    case Hold='HOLD';
    case Exit='EXIT';
    case EmergencyExit='EMERGENCY_EXIT';
}
