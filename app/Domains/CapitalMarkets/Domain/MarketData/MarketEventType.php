<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketEventType:string
{
    case Quote='QUOTE';
    case Bbo='BBO';
    case Trade='TRADE';
    case OrderBookSnapshot='ORDER_BOOK_SNAPSHOT';
    case OrderBookDelta='ORDER_BOOK_DELTA';
    case Candle='CANDLE';
    case Volume='VOLUME';
    case ReferencePrice='REFERENCE_PRICE';
    case FundingRate='FUNDING_RATE';
    case OpenInterest='OPEN_INTEREST';
    case MarkPrice='MARK_PRICE';
    case IndexPrice='INDEX_PRICE';
    case InstrumentMetadata='INSTRUMENT_METADATA';
}
