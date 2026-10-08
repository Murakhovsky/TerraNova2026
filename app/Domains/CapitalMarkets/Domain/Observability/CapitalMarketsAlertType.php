<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Observability;

enum CapitalMarketsAlertType:string
{
    case DataFeedStale='DATA_FEED_STALE';
    case DataFeedDown='DATA_FEED_DOWN';
    case ReferencePriceUnavailable='REFERENCE_PRICE_UNAVAILABLE';
    case AbnormalSpread='ABNORMAL_SPREAD';
    case RiskLimitBreached='RISK_LIMIT_BREACHED';
    case CapitalReservationFailed='CAPITAL_RESERVATION_FAILED';
    case ExecutionFailed='EXECUTION_FAILED';
    case UnhedgedPosition='UNHEDGED_POSITION';
    case LedgerInconsistency='LEDGER_INCONSISTENCY';
    case PositionReconciliationError='POSITION_RECONCILIATION_ERROR';
    case RiskEnvelopeApproaching='RISK_ENVELOPE_APPROACHING';
    case RiskEnvelopeBreached='RISK_ENVELOPE_BREACHED';
    case VenueConcentrationHigh='VENUE_CONCENTRATION_HIGH';
    case AssetConcentrationHigh='ASSET_CONCENTRATION_HIGH';
    case StrategyConcentrationHigh='STRATEGY_CONCENTRATION_HIGH';
    case MarginUtilizationHigh='MARGIN_UTILIZATION_HIGH';
    case LiquidityRiskHigh='LIQUIDITY_RISK_HIGH';
    case DailyLossLimitNear='DAILY_LOSS_LIMIT_NEAR';
    case PortfolioDrawdownNear='PORTFOLIO_DRAWDOWN_NEAR';
    case CapitalBufferLow='CAPITAL_BUFFER_LOW';
}
