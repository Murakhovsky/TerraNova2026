<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\MarketData;
enum FundingRateType:string
{
    case NormalizedLongsPayShorts='NORMALIZED_LONGS_PAY_SHORTS';
    case VenueNative='VENUE_NATIVE';
}
