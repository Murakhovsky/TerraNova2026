<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketSnapshot extends ValueObject
{
    /**
     * @param list<MarketState> $instrumentStates
     * @param list<ReferenceMarketState> $referenceStates
     * @param array<string,int> $sourceVersions
     */
    public function __construct(
        public string $snapshotId,
        public DateTimeImmutable $createdAt,
        public array $instrumentStates,
        public array $referenceStates,
        public array $sourceVersions,
    ){
        if($this->snapshotId===''||trim($this->snapshotId)!==$this->snapshotId||mb_strlen($this->snapshotId)>190){
            throw new InvalidArgumentException('Market snapshot id is invalid.');
        }
        foreach($this->instrumentStates as $state){
            if(!$state instanceof MarketState)throw new InvalidArgumentException('Market snapshot instrument states must be typed.');
        }
        foreach($this->referenceStates as $state){
            if(!$state instanceof ReferenceMarketState)throw new InvalidArgumentException('Market snapshot reference states must be typed.');
        }
        foreach($this->sourceVersions as $source=>$version){
            if(!is_string($source)||$source===''||!is_int($version)||$version<1){
                throw new InvalidArgumentException('Market snapshot source versions are invalid.');
            }
        }
    }

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'snapshot_id'=>$this->snapshotId,
            'created_at'=>$this->createdAt->format(DATE_ATOM),
            'instrument_states'=>array_map(static fn(MarketState $state):array=>$state->toArray(),$this->instrumentStates),
            'reference_states'=>array_map(static fn(ReferenceMarketState $state):array=>$state->toArray(),$this->referenceStates),
            'source_versions'=>$this->sourceVersions,
        ];
    }
}
