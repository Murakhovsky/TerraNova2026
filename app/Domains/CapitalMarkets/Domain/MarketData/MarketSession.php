<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketSession:string
{
    case Regular='REGULAR';
    case PreMarket='PRE_MARKET';
    case AfterHours='AFTER_HOURS';
    case Overnight='OVERNIGHT';
    case Closed='CLOSED';
    case Unknown='UNKNOWN';
}
