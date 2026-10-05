<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketQualityFlag:string
{
    case Stale='STALE';
    case Late='LATE';
    case OutOfOrder='OUT_OF_ORDER';
    case Duplicate='DUPLICATE';
    case SequenceGap='SEQUENCE_GAP';
    case AbnormalPrice='ABNORMAL_PRICE';
    case SourceDegraded='SOURCE_DEGRADED';
    case ReferenceMismatch='REFERENCE_MISMATCH';
    case ClockUncertain='CLOCK_UNCERTAIN';
    case ClockAnomaly='CLOCK_ANOMALY';
    case Incomplete='INCOMPLETE';
    case CrossedMarket='CROSSED_MARKET';
    case UnknownInstrument='UNKNOWN_INSTRUMENT';
    case OrderBookInvalid='ORDER_BOOK_INVALID';
}
