<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Audit;

enum CapitalMarketsAuditAction: string
{
    case InstrumentRegistered = 'capital_markets.instrument.registered';
    case InstrumentUpdated = 'capital_markets.instrument.updated';
    case RelationshipDefined = 'capital_markets.relationship.defined';
    case VenueRegistered = 'capital_markets.venue.registered';
    case ResearchHypothesisCreated = 'capital_markets.research.hypothesis_created';
    case PaperExecutionRequested = 'capital_markets.paper.execution_requested';
    case LiveExecutionRequested = 'capital_markets.live.execution_requested';
    case RiskDecisionRecorded = 'capital_markets.risk.decision_recorded';
}
