<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\DTO;

use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrument;

final readonly class ResolvedMarketInstrument
{
    public function __construct(
        public InstrumentDescriptor $instrument,
        public ?VenueInstrument $venueInstrument,
    ){}
}
