<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Venue;

enum VenueCapability:string
{
    case MarketData='MARKET_DATA';
    case OrderBook='ORDER_BOOK';
    case Trading='TRADING';
    case HistoricalData='HISTORICAL_DATA';
    case Streaming='STREAMING';
    case FundingData='FUNDING_DATA';
    case Margin='MARGIN';
    case Deposit='DEPOSIT';
    case Withdrawal='WITHDRAWAL';
    case Settlement='SETTLEMENT';
}
