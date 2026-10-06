<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Feature;

enum CapitalMarketsFeatureFlag:string
{
    case DomainEnabled='capital_markets.enabled';
    case InstrumentRegistry='capital_markets.instrument_registry.enabled';
    case Relationships='capital_markets.relationships.enabled';
    case Venues='capital_markets.venues.enabled';
    case PaperTrading='capital_markets.paper_trading.enabled';
    case LiveTrading='capital_markets.live_trading.enabled';
    case AutoExecution='capital_markets.auto_execution.enabled';
    case MarketData='capital_markets.market_data.enabled';
    case MarketDataStreaming='capital_markets.market_data.streaming.enabled';
    case MarketDataHistory='capital_markets.market_data.history.enabled';
    case MarketDataBybit='capital_markets.market_data.bybit.enabled';
    case MarketDataMassive='capital_markets.market_data.massive.enabled';

    /** @return list<string> */
    public static function values():array{return array_map(static fn(self $case):string=>$case->value,self::cases());}
}
