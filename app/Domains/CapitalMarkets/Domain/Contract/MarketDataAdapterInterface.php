<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Contract;

use Domains\CapitalMarkets\Domain\MarketData\MarketDataBatch;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataCapability;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataInstrumentTarget;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;

interface MarketDataAdapterInterface
{
    public function adapterType():string;
    public function getSource():string;

    /** @return list<MarketDataCapability> */
    public function getCapabilities():array;

    public function supports(MarketDataCapability $capability,MarketDataInstrumentTarget $target):bool;

    public function resolveInstrument(
        string $organizationId,
        MarketSourceDescriptor $source,
        MarketDataInstrumentTarget $target,
    ):?string;

    public function getSnapshot(
        string $organizationId,
        MarketSourceDescriptor $source,
        MarketDataInstrumentTarget $target,
    ):MarketDataBatch;

    public function getHealth(string $organizationId,MarketSourceDescriptor $source):MarketSourceHealth;
}
