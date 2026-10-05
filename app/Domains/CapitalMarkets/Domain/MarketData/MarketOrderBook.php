<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use InvalidArgumentException;

final readonly class MarketOrderBook implements MarketEventPayload
{
    /**
     * @param list<OrderBookLevel> $bids
     * @param list<OrderBookLevel> $asks
     */
    public function __construct(
        public bool $snapshot,
        public array $bids,
        public array $asks,
        public string $sequence,
    ){
        if(trim($this->sequence)==='')throw new InvalidArgumentException('Order-book sequence is required.');
        foreach([...$this->bids,...$this->asks] as $level){
            if(!$level instanceof OrderBookLevel)throw new InvalidArgumentException('Order-book levels must be typed.');
        }
    }

    public function applyDelta(self $delta):self
    {
        if($delta->snapshot)throw new InvalidArgumentException('Snapshot cannot be applied as a delta.');
        return new self(true,self::merge($this->bids,$delta->bids,true),self::merge($this->asks,$delta->asks,false),$delta->sequence);
    }

    /** @param list<OrderBookLevel> $base @param list<OrderBookLevel> $delta @return list<OrderBookLevel> */
    private static function merge(array $base,array $delta,bool $descending):array
    {
        $levels=[];
        foreach($base as $level)$levels[$level->price->value->value()]=$level;
        foreach($delta as $level){
            $key=$level->price->value->value();
            if($level->quantity->value->isZero())unset($levels[$key]);else$levels[$key]=$level;
        }
        $levels=array_values($levels);
        usort($levels,static function(OrderBookLevel $a,OrderBookLevel $b)use($descending):int{
            $cmp=$a->price->value->compareTo($b->price->value);
            return $descending?-$cmp:$cmp;
        });
        return $levels;
    }

    public function toArray():array{return [
        'snapshot'=>$this->snapshot,
        'sequence'=>$this->sequence,
        'bids'=>array_map(static fn(OrderBookLevel $level):array=>$level->toArray(),$this->bids),
        'asks'=>array_map(static fn(OrderBookLevel $level):array=>$level->toArray(),$this->asks),
    ];}
}
