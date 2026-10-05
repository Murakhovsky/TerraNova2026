<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Contract;

use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifier;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;

interface InstrumentRepository
{
    /** @param list<InstrumentIdentifier> $identifiers */
    public function save(string $organizationId,InstrumentDescriptor $instrument,array $identifiers):void;
    public function get(string $organizationId,InstrumentId $id):?InstrumentDescriptor;
    public function findByIdentifier(string $organizationId,InstrumentIdentifier $identifier):?InstrumentDescriptor;

    /** @param array<string,string> $filters @return list<InstrumentDescriptor> */
    public function list(string $organizationId,array $filters=[],int $limit=100):array;

    /** @return list<InstrumentIdentifier> */
    public function identifiers(string $organizationId,InstrumentId $id):array;
}
