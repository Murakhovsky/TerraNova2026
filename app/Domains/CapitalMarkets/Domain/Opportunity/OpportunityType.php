<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Opportunity;
enum OpportunityType:string
{
    case TokenizedEquityDislocation='TOKENIZED_EQUITY_DISLOCATION';
    case CrossVenueTokenizedEquity='CROSS_VENUE_TOKENIZED_EQUITY';
    case SpotPerpBasis='SPOT_PERP_BASIS';
    case FundingCapture='FUNDING_CAPTURE';
    case CrossVenueFunding='CROSS_VENUE_FUNDING';
}
