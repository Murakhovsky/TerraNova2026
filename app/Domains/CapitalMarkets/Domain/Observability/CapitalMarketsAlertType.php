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
}
