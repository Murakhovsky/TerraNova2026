<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Model;

enum CapitalMarketsCapability:string
{
    case View='capital_markets.view';
    case Manage='capital_markets.manage';
    case InstrumentView='capital_markets.instrument.view';
    case InstrumentManage='capital_markets.instrument.manage';
    case RelationshipView='capital_markets.relationship.view';
    case RelationshipManage='capital_markets.relationship.manage';
    case VenueView='capital_markets.venue.view';
    case VenueManage='capital_markets.venue.manage';
    case AuditView='capital_markets.audit.view';
    case MarketDataView='capital_markets.market_data.view';
    case MarketDataManage='capital_markets.market_data.manage';
    case MarketDataSourceView='capital_markets.market_data.source.view';
    case MarketDataSourceManage='capital_markets.market_data.source.manage';
    case MarketDataQualityView='capital_markets.market_data.quality.view';
    case MarketDataHistoryView='capital_markets.market_data.history.view';
    case MarketDataReplayManage='capital_markets.market_data.replay.manage';
    case OpportunityView='capital_markets.opportunity.view';
    case PaperExecute='capital_markets.paper.execute';

    case ResearchView='capital_markets.research.view';
    case ResearchManage='capital_markets.research.manage';
    case ResearchExperimentRun='capital_markets.research.experiment.run';
    case StrategyVersionManage='capital_markets.strategy.version.manage';
    case StrategyPromote='capital_markets.strategy.promote';
    case StrategyDemote='capital_markets.strategy.demote';
    case StrategyReject='capital_markets.strategy.reject';
    case ResearchAgentUse='capital_markets.research.agent.use';

    /** @return list<string> */
    public static function values():array{return array_map(static fn(self $case):string=>$case->value,self::cases());}
}
