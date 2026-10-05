<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Feature;

enum CapitalMarketsFeatureFlag: string
{
    case DomainEnabled = 'capital_markets.enabled';
    case ExternalMarketData = 'capital_markets.external_market_data';
    case AgentResearch = 'capital_markets.agent_research';
    case PaperTrading = 'capital_markets.paper_trading';
    case LiveTrading = 'capital_markets.live_trading';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
