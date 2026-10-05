<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Contract;

use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationship;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Instrument\RelationshipId;

interface RelationshipRepository
{
    public function save(string $organizationId,EconomicRelationship $relationship):void;
    public function get(string $organizationId,RelationshipId $id):?EconomicRelationship;

    /** @return list<EconomicRelationship> */
    public function forInstrument(string $organizationId,InstrumentId $instrumentId):array;

    /** @return list<EconomicRelationship> */
    public function list(string $organizationId,int $limit=200):array;
}
