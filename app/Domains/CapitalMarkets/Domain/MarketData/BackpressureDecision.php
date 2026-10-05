<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum BackpressureDecision:string
{
    case Process='PROCESS';
    case Coalesce='COALESCE';
    case Preserve='PRESERVE';
    case Reject='REJECT';
}
