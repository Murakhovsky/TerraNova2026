<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Contract;

use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataBatch;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataCapability;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrument;

interface MarketDataAdapterInterface
{
    public function adapterType():string;
    public function getSource():string;

    /** @return list<MarketDataCapability> */
    public function getCapabilities():array;

    public function supports(MarketDataCapability $capability,InstrumentDescriptor $instrument):bool;

    public function resolveInstrument(MarketSourceDescriptor $source,VenueInstrument $instrument):?string;

    public function getSnapshot(MarketSourceDescriptor $source,VenueInstrument $instrument):MarketDataBatch;

    public function getHealth(MarketSourceDescriptor $source):MarketSourceHealth;
}
