<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketStatus:string
{
    case Open='OPEN';
    case Closed='CLOSED';
    case PreMarket='PRE_MARKET';
    case AfterHours='AFTER_HOURS';
    case Overnight='OVERNIGHT';
    case Halted='HALTED';
    case Unknown='UNKNOWN';
}
