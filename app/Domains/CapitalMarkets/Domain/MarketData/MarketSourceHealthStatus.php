<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketSourceHealthStatus:string
{
    case Healthy='HEALTHY';
    case Degraded='DEGRADED';
    case Failed='FAILED';
}
