<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum TradeSide:string
{
    case Buy='BUY';
    case Sell='SELL';
}
