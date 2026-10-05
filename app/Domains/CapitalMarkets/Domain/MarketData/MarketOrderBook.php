<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketOrderBook extends ValueObject implements MarketObservation
{
    /**
     * @param list<OrderBookLevel> $bids
     * @param list<OrderBookLevel> $asks
     */
    public function __construct(
        public MarketEventType $type,
        public ?string $sequence,
        public array $bids,
        public array $asks,
    ){
        if(!in_array($this->type,[MarketEventType::OrderBookSnapshot,MarketEventType::OrderBookDelta],true)){
            throw new InvalidArgumentException('Order book must be SNAPSHOT or DELTA.');
        }
        if($this->sequence!==null&&($this->sequence===''||trim($this->sequence)!==$this->sequence||mb_strlen($this->sequence)>190)){
            throw new InvalidArgumentException('Order-book sequence is invalid.');
        }
        foreach([...$this->bids,...$this->asks] as $level){
            if(!$level instanceof OrderBookLevel)throw new InvalidArgumentException('Order-book levels must be typed.');
        }
        $this->assertUniquePrices($this->bids,'bid');
        $this->assertUniquePrices($this->asks,'ask');
    }

    public function eventType():MarketEventType{return $this->type;}

    public function toArray():array
    {
        return [
            'sequence'=>$this->sequence,
            'bids'=>array_map(static fn(OrderBookLevel $level):array=>$level->toArray(),$this->bids),
            'asks'=>array_map(static fn(OrderBookLevel $level):array=>$level->toArray(),$this->asks),
        ];
    }

    /** @param list<OrderBookLevel> $levels */
    private function assertUniquePrices(array $levels,string $side):void
    {
        $seen=[];
        foreach($levels as $level){
            $key=$level->price->value->value();
            if(isset($seen[$key]))throw new InvalidArgumentException('Duplicate '.$side.' order-book price level.');
            $seen[$key]=true;
        }
    }
}
