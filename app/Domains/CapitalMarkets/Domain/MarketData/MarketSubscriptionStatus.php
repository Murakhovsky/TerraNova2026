<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketSubscriptionStatus:string
{
    case Pending='PENDING';
    case Active='ACTIVE';
    case Degraded='DEGRADED';
    case Failed='FAILED';
    case Disabled='DISABLED';
}
