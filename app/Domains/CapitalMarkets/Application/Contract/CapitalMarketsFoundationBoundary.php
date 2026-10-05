<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use Domains\CapitalMarkets\Application\Command\ActivateInstrument;
use Domains\CapitalMarkets\Application\Command\AddInstrumentIdentifier;
use Domains\CapitalMarkets\Application\Command\CreateInstrument;
use Domains\CapitalMarkets\Application\Command\CreateRelationship;
use Domains\CapitalMarkets\Application\Command\CreateVenue;
use Domains\CapitalMarkets\Application\Command\RegisterVenueInstrument;
use Domains\CapitalMarkets\Application\Command\SuspendInstrument;
use Domains\CapitalMarkets\Application\Command\UpdateInstrument;
use Domains\CapitalMarkets\Application\Query\FindInstrumentByIdentifier;
use Domains\CapitalMarkets\Application\Query\GetInstrument;
use Domains\CapitalMarkets\Application\Query\GetRelatedInstruments;
use Domains\CapitalMarkets\Application\Query\GetVenueInstruments;
use Domains\CapitalMarkets\Application\Query\ListInstruments;
use Domains\CapitalMarkets\Application\Query\ListRelationships;
use Domains\CapitalMarkets\Application\Query\ListVenues;

interface CapitalMarketsFoundationBoundary
{
    /** @return array<string,mixed> */
    public function createInstrument(CreateInstrument $command):array;
    /** @return array<string,mixed> */
    public function updateInstrument(UpdateInstrument $command):array;
    /** @return array<string,mixed> */
    public function activateInstrument(ActivateInstrument $command):array;
    /** @return array<string,mixed> */
    public function suspendInstrument(SuspendInstrument $command):array;
    /** @return array<string,mixed> */
    public function addInstrumentIdentifier(AddInstrumentIdentifier $command):array;
    /** @return array<string,mixed> */
    public function createRelationship(CreateRelationship $command):array;
    /** @return array<string,mixed> */
    public function createVenue(CreateVenue $command):array;
    /** @return array<string,mixed> */
    public function registerVenueInstrument(RegisterVenueInstrument $command):array;

    /** @return array<string,mixed>|null */
    public function getInstrument(GetInstrument $query):?array;
    /** @return list<array<string,mixed>> */
    public function listInstruments(ListInstruments $query):array;
    /** @return array<string,mixed>|null */
    public function findInstrumentByIdentifier(FindInstrumentByIdentifier $query):?array;
    /** @return list<array<string,mixed>> */
    public function getRelatedInstruments(GetRelatedInstruments $query):array;
    /** @return list<array<string,mixed>> */
    public function listRelationships(ListRelationships $query):array;
    /** @return list<array<string,mixed>> */
    public function listVenues(ListVenues $query):array;
    /** @return list<array<string,mixed>> */
    public function getVenueInstruments(GetVenueInstruments $query):array;
}
