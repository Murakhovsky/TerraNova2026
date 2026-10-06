<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketDataCapability:string
{
    case Ticker='TICKER';
    case Bbo='BBO';
    case OrderBook='ORDER_BOOK';
    case Trades='TRADES';
    case Candles='CANDLES';
    case Volume='VOLUME';
    case Funding='FUNDING';
    case OpenInterest='OPEN_INTEREST';
    case ReferencePrice='REFERENCE_PRICE';
    case HistoricalQuotes='HISTORICAL_QUOTES';
    case Streaming='STREAMING';
}
