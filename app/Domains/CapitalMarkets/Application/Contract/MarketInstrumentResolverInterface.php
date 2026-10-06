<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use Domains\CapitalMarkets\Application\DTO\ResolvedMarketInstrument;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataInstrumentTarget;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;

interface MarketInstrumentResolverInterface
{
    public function resolve(string $organizationId,MarketSourceDescriptor $source,string $externalInstrument):?ResolvedMarketInstrument;

    public function target(
        string $organizationId,
        MarketSourceDescriptor $source,
        InstrumentId $instrumentId,
    ):?MarketDataInstrumentTarget;
}
