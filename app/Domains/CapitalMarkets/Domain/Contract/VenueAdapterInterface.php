<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Contract;

use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Venue\VenueCapability;
use Domains\CapitalMarkets\Domain\Venue\VenueDescriptor;

interface VenueAdapterInterface
{
    public function getVenue():VenueDescriptor;

    /** @return list<VenueCapability> */
    public function getCapabilities():array;

    public function supportsInstrument(InstrumentDescriptor $instrument):bool;
    public function resolveSymbol(InstrumentDescriptor $instrument):?string;
}
