<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Contract;

use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Venue\VenueCapability;
use Domains\CapitalMarkets\Domain\Venue\VenueDescriptor;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrument;

interface VenueRepository
{
    /** @param list<VenueCapability> $capabilities */
    public function save(string $organizationId,VenueDescriptor $venue,array $capabilities):void;
    public function get(string $organizationId,VenueId $id):?VenueDescriptor;

    /** @return list<VenueDescriptor> */
    public function list(string $organizationId,int $limit=100):array;

    /** @return list<VenueCapability> */
    public function capabilities(string $organizationId,VenueId $id):array;

    public function registerInstrument(string $organizationId,VenueInstrument $mapping):void;

    /** @return list<VenueInstrument> */
    public function instruments(string $organizationId,VenueId $venueId):array;

    /** @return list<VenueInstrument> */
    public function venuesForInstrument(string $organizationId,InstrumentId $instrumentId):array;
}
