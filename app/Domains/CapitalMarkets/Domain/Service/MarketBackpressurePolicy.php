<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\MarketData\BackpressureDecision;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use InvalidArgumentException;

final readonly class MarketBackpressurePolicy
{
    public function __construct(private int $maximumQueueLag)
    {
        if($this->maximumQueueLag<1)throw new InvalidArgumentException('Maximum queue lag must be positive.');
    }

    public function decide(MarketEventType $type,int $queueLag):BackpressureDecision
    {
        if($queueLag<0)throw new InvalidArgumentException('Queue lag cannot be negative.');
        if($queueLag<=$this->maximumQueueLag)return BackpressureDecision::Process;

        return match($type){
            MarketEventType::Quote,
            MarketEventType::Bbo,
            MarketEventType::Volume,
            MarketEventType::ReferencePrice,
            MarketEventType::MarkPrice,
            MarketEventType::IndexPrice,
            MarketEventType::OpenInterest,
            MarketEventType::FundingRate=>BackpressureDecision::Coalesce,
            MarketEventType::Trade=>BackpressureDecision::Preserve,
            MarketEventType::OrderBookSnapshot,
            MarketEventType::OrderBookDelta=>BackpressureDecision::Reject,
            MarketEventType::Candle=>BackpressureDecision::Preserve,
        };
    }
}
