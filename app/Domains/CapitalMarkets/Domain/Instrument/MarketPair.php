<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketPair extends ValueObject
{
    public function __construct(
        public string $id,
        public InstrumentId $instrumentA,
        public InstrumentId $instrumentB,
        public ?RelationshipId $relationshipId,
        public MarketPairPurpose $purpose,
        public MarketPairStatus $status=MarketPairStatus::Active,
    ){
        if($this->id===''||trim($this->id)!==$this->id||mb_strlen($this->id)>190){
            throw new InvalidArgumentException('Market pair id must be a canonical non-empty identifier.');
        }
        if($this->instrumentA->equals($this->instrumentB)){
            throw new InvalidArgumentException('Market pair requires two different instruments.');
        }
    }
}
