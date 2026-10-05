<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketComparabilityStatus:string
{
    case Comparable='COMPARABLE';
    case CrossCurrency='CROSS_CURRENCY';
    case ReferenceStale='REFERENCE_STALE';
    case SessionMismatch='SESSION_MISMATCH';
    case InsufficientData='INSUFFICIENT_DATA';
    case Untrusted='UNTRUSTED';
    case NotComparable='NOT_COMPARABLE';
}
