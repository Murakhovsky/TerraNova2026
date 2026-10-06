<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketDataMode:string
{
    case Live='LIVE';
    case Delayed='DELAYED';
    case Historical='HISTORICAL';
    case Replay='REPLAY';
}
