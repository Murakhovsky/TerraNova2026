<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketGapStatus:string
{
    case Detected='DETECTED';
    case Backfilling='BACKFILLING';
    case Filled='FILLED';
    case Unrecoverable='UNRECOVERABLE';
    case Ignored='IGNORED';
}
