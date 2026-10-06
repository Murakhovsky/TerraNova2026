<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
enum CapitalMarketsRiskType:string
{
    case BasisRisk='BASIS_RISK';
    case FundingReversalRisk='FUNDING_REVERSAL_RISK';
    case LiquidationRisk='LIQUIDATION_RISK';
    case MarginRisk='MARGIN_RISK';
    case LeggingRisk='LEGGING_RISK';
    case HedgeDriftRisk='HEDGE_DRIFT_RISK';
    case BorrowRisk='BORROW_RISK';
    case CrossVenueCapitalRisk='CROSS_VENUE_CAPITAL_RISK';
}
