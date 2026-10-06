<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Opportunity;
enum HypothesisCode:string
{
    case TokenizedEquityDislocation='H1';
    case CrossVenueTokenizedEquityArbitrage='H2';
    case SpotPerpetualBasis='H4';
    case FundingRateCapture='H5';
    case CrossVenueFunding='H6';
}
