<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

enum TokenizedEquityEventType:string
{
    case MarketSnapshotUpdated='MarketSnapshotUpdated';
    case SpreadCandidateDetected='SpreadCandidateDetected';
    case SpreadCandidateRejected='SpreadCandidateRejected';
    case OpportunityCreated='OpportunityCreated';
    case OpportunityValidated='OpportunityValidated';
    case OpportunityRejected='OpportunityRejected';
    case OpportunityExpired='OpportunityExpired';
    case RiskAssessmentCompleted='RiskAssessmentCompleted';
    case RiskRejected='RiskRejected';
    case CapitalReserved='CapitalReserved';
    case CapitalReservationFailed='CapitalReservationFailed';
    case CapitalReleased='CapitalReleased';
    case ExecutionPlanCreated='ExecutionPlanCreated';
    case ExecutionGroupStarted='ExecutionGroupStarted';
    case OrderCreated='OrderCreated';
    case OrderSubmitted='OrderSubmitted';
    case OrderFilled='OrderFilled';
    case OrderPartiallyFilled='OrderPartiallyFilled';
    case OrderRejected='OrderRejected';
    case ExecutionLegFailed='ExecutionLegFailed';
    case CompensationStarted='CompensationStarted';
    case ExecutionGroupCompleted='ExecutionGroupCompleted';
    case ExecutionGroupFailed='ExecutionGroupFailed';
    case LedgerTransactionPosted='LedgerTransactionPosted';
    case PositionOpened='PositionOpened';
    case PositionChanged='PositionChanged';
    case PositionClosed='PositionClosed';
    case PnLUpdated='PnLUpdated';
    case HypothesisObservationRecorded='HypothesisObservationRecorded';
    case HypothesisMetricsUpdated='HypothesisMetricsUpdated';
}
