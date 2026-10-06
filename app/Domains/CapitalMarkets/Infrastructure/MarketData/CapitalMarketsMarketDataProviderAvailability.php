<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData;

use Domains\CapitalMarkets\Application\Contract\MarketDataProviderAvailabilityInterface;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureFlag;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureGate;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitPerpetualMarketDataAdapter;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitSpotMarketDataAdapter;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Massive\MassiveStocksReferenceAdapter;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Kraken\KrakenSpotMarketDataAdapter;

final readonly class CapitalMarketsMarketDataProviderAvailability implements MarketDataProviderAvailabilityInterface
{
    public function __construct(private CapitalMarketsFeatureGate $features){}

    public function enabled(string $organizationId,MarketSourceDescriptor $source):bool
    {
        if(!$this->features->enabled(CapitalMarketsFeatureFlag::MarketData,$organizationId))return false;
        $providerFlag=match($source->adapterType){
            BybitSpotMarketDataAdapter::ADAPTER_TYPE,
            BybitPerpetualMarketDataAdapter::ADAPTER_TYPE=>CapitalMarketsFeatureFlag::MarketDataBybit,
            KrakenSpotMarketDataAdapter::ADAPTER_TYPE=>CapitalMarketsFeatureFlag::MarketDataKraken,
            MassiveStocksReferenceAdapter::ADAPTER_TYPE=>CapitalMarketsFeatureFlag::MarketDataMassive,
            default=>null,
        };
        return $providerFlag===null||$this->features->enabled($providerFlag,$organizationId);
    }
}
