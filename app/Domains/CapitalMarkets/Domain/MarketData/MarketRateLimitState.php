<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketRateLimitState:string
{
    case Normal='NORMAL';
    case Throttled='THROTTLED';
    case Exhausted='EXHAUSTED';
    case Unknown='UNKNOWN';
}
