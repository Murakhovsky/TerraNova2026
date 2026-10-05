<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketTrustStatus:string
{
    case Trusted='TRUSTED';
    case Degraded='DEGRADED';
    case Stale='STALE';
    case Untrusted='UNTRUSTED';
    case Unavailable='UNAVAILABLE';
}
