<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;
use Domains\CapitalMarkets\Domain\MarketData\OrderBookLevel;

final readonly class OrderBookRebuilder
{
    public function apply(MarketOrderBook $current,MarketOrderBook $delta):MarketOrderBook
    {
        if($current->eventType()!==MarketEventType::OrderBookSnapshot){
            throw new DomainException('Order-book delta requires a valid snapshot.');
        }
        if($delta->eventType()!==MarketEventType::OrderBookDelta){
            throw new DomainException('Order-book rebuilder accepts DELTA updates only.');
        }

        $bids=$this->index($current->bids);
        $asks=$this->index($current->asks);
        $this->applySide($bids,$delta->bids);
        $this->applySide($asks,$delta->asks);

        $bidLevels=array_values($bids);
        $askLevels=array_values($asks);
        usort($bidLevels,static fn(OrderBookLevel $a,OrderBookLevel $b):int=>$b->price->value->compareTo($a->price->value));
        usort($askLevels,static fn(OrderBookLevel $a,OrderBookLevel $b):int=>$a->price->value->compareTo($b->price->value));

        return new MarketOrderBook(
            MarketEventType::OrderBookSnapshot,
            $delta->sequence??$current->sequence,
            $bidLevels,
            $askLevels,
        );
    }

    /** @param list<OrderBookLevel> $levels @return array<string,OrderBookLevel> */
    private function index(array $levels):array
    {
        $out=[];
        foreach($levels as $level)$out[$level->price->value->value()]=$level;
        return $out;
    }

    /** @param array<string,OrderBookLevel> $state @param list<OrderBookLevel> $changes */
    private function applySide(array &$state,array $changes):void
    {
        foreach($changes as $level){
            $key=$level->price->value->value();
            if($level->quantity->value->isZero())unset($state[$key]);
            else $state[$key]=$level;
        }
    }
}
