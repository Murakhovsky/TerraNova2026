<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Audit;

enum CapitalMarketsAuditAction:string
{
    case InstrumentCreated='capital_markets.instrument.created';
    case InstrumentUpdated='capital_markets.instrument.updated';
    case InstrumentStatusChanged='capital_markets.instrument.status_changed';
    case RelationshipCreated='capital_markets.relationship.created';
    case RelationshipUpdated='capital_markets.relationship.updated';
    case VenueCreated='capital_markets.venue.created';
    case VenueUpdated='capital_markets.venue.updated';
    case VenueStatusChanged='capital_markets.venue.status_changed';
    case VenueInstrumentRegistered='capital_markets.venue_instrument.registered';
    case PermissionChanged='capital_markets.permission.changed';
    case FeatureFlagChanged='capital_markets.feature_flag.changed';
    case MarketDataSourceCreated='capital_markets.market_data.source.created';
    case MarketDataSourceEnabled='capital_markets.market_data.source.enabled';
    case MarketDataSourceDisabled='capital_markets.market_data.source.disabled';
    case MarketDataSubscriptionSaved='capital_markets.market_data.subscription.saved';
    case MarketDataPollTriggered='capital_markets.market_data.poll.triggered';
    case ResearchHypothesisCreated='capital_markets.research.hypothesis.created';
    case ResearchHypothesisRevised='capital_markets.research.hypothesis.revised';
    case ResearchHypothesisRejected='capital_markets.research.hypothesis.rejected';
    case ResearchExperimentCreated='capital_markets.research.experiment.created';
    case ResearchExperimentStatusChanged='capital_markets.research.experiment.status_changed';
    case ResearchDatasetFrozen='capital_markets.research.dataset.frozen';
    case ResearchStrategyVersionCreated='capital_markets.research.strategy_version.created';
    case ResearchScorecardCreated='capital_markets.research.scorecard.created';
    case ResearchPromotionEvaluated='capital_markets.research.promotion.evaluated';
    case ResearchDemotionEvaluated='capital_markets.research.demotion.evaluated';
    case ResearchPaperRunStarted='capital_markets.research.paper_run.started';
    case ResearchPaperRunCompleted='capital_markets.research.paper_run.completed';
    case ResearchPaperRunCancelled='capital_markets.research.paper_run.cancelled';
}
