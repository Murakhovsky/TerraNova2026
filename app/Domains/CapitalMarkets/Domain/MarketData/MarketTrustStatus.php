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

    public function isUsableForDecision():bool
    {
        return $this===self::Trusted||$this===self::Degraded;
    }
}
