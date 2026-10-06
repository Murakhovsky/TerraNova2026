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
}
