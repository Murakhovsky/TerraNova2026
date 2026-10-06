<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketSourceRole:string
{
    case TradingSource='TRADING_SOURCE';
    case ReferenceSource='REFERENCE_SOURCE';
    case ValidationSource='VALIDATION_SOURCE';
    case HistoricalSource='HISTORICAL_SOURCE';
    case FxSource='FX_SOURCE';
}
