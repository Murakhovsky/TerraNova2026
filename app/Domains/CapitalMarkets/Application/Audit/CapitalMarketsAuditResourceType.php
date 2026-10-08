<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Audit;

enum CapitalMarketsAuditResourceType:string
{
    case Instrument='capital_markets.instrument';
    case Relationship='capital_markets.relationship';
    case Venue='capital_markets.venue';
    case VenueInstrument='capital_markets.venue_instrument';
    case Permission='capital_markets.permission';
    case FeatureFlag='capital_markets.feature_flag';
    case MarketDataSource='capital_markets.market_data.source';
    case MarketDataSubscription='capital_markets.market_data.subscription';
    case ResearchHypothesis='capital_markets.research.hypothesis';
    case ResearchDataset='capital_markets.research.dataset';
    case ResearchExperiment='capital_markets.research.experiment';
    case ResearchStrategyVersion='capital_markets.research.strategy_version';
    case ResearchScorecard='capital_markets.research.scorecard';
    case ResearchPromotionDecision='capital_markets.research.promotion_decision';
    case ResearchPaperRun='capital_markets.research.paper_run';
    case RiskEnvelope='capital_markets.risk.envelope';
    case PortfolioRiskSnapshot='capital_markets.portfolio.risk_snapshot';
    case AllocationPlan='capital_markets.allocation.plan';
    case StrategyAllocation='capital_markets.strategy.allocation';
    case RebalancePlan='capital_markets.rebalance.plan';
    case StressResult='capital_markets.risk.stress_result';
    case PortfolioAgentRun='capital_markets.portfolio.agent_run';
}
