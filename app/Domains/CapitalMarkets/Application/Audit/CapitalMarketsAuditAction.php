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
}
