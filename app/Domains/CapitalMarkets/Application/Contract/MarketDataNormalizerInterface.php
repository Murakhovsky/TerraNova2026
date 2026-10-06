<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use Domains\CapitalMarkets\Application\DTO\DecodedMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;

interface MarketDataNormalizerInterface
{
    public function normalize(
        string $organizationId,
        MarketSourceDescriptor $source,
        RawMarketEvent $raw,
        DecodedMarketEvent $decoded,
    ):CanonicalMarketEvent;
}
