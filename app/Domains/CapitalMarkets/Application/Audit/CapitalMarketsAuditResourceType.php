<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Audit;

enum CapitalMarketsAuditResourceType: string
{
    case Instrument = 'capital_markets.instrument';
    case Relationship = 'capital_markets.relationship';
    case Venue = 'capital_markets.venue';
    case Hypothesis = 'capital_markets.hypothesis';
    case Opportunity = 'capital_markets.opportunity';
    case Strategy = 'capital_markets.strategy';
    case Execution = 'capital_markets.execution';
    case RiskDecision = 'capital_markets.risk_decision';
}
