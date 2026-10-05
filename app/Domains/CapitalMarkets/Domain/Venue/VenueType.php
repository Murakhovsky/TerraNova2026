<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Venue;

enum VenueType: string
{
    case Broker = 'broker';
    case StockExchange = 'stock_exchange';
    case Cex = 'cex';
    case Dex = 'dex';
    case Amm = 'amm';
    case PerpetualDex = 'perpetual_dex';
    case TokenizedSecuritiesVenue = 'tokenized_securities_venue';
    case RwaPlatform = 'rwa_platform';
    case DataProvider = 'data_provider';
}
