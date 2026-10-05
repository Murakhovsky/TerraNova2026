<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MarketSnapshot
{
    /**
     * @param list<MarketState> $marketStates
     * @param list<ReferenceMarketState> $referenceStates
     * @param array<string,int> $sourceVersions
     */
    public function __construct(
        public string $snapshotId,
        public DateTimeImmutable $createdAt,
        public array $marketStates,
        public array $referenceStates,
        public array $sourceVersions,
    ){
        if(trim($this->snapshotId)==='')throw new InvalidArgumentException('Snapshot id is required.');
        foreach($this->marketStates as $state)if(!$state instanceof MarketState)throw new InvalidArgumentException('Snapshot market states must be typed.');
        foreach($this->referenceStates as $state)if(!$state instanceof ReferenceMarketState)throw new InvalidArgumentException('Snapshot reference states must be typed.');
    }

    public function toArray():array{return [
        'snapshot_id'=>$this->snapshotId,'created_at'=>$this->createdAt->format(DATE_ATOM),
        'market_states'=>array_map(static fn(MarketState $state):array=>$state->toArray(),$this->marketStates),
        'reference_states'=>array_map(static fn(ReferenceMarketState $state):array=>$state->toArray(),$this->referenceStates),
        'source_versions'=>$this->sourceVersions,
    ];}
}
