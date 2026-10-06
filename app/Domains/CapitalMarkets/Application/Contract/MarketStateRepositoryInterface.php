<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceMarketState;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

interface MarketStateRepositoryInterface
{
    public function save(string $organizationId,MarketState $state):void;
    public function get(string $organizationId,VenueId $venueId,InstrumentId $instrumentId):?MarketState;

    /** @return list<MarketState> */
    public function list(string $organizationId,int $limit=200):array;

    public function saveReference(string $organizationId,ReferenceMarketState $state):void;
    public function getReference(string $organizationId,MarketSourceId $sourceId,InstrumentId $instrumentId):?ReferenceMarketState;

    /** @return list<ReferenceMarketState> */
    public function listReferences(string $organizationId,int $limit=200):array;
}
