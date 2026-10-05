<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use InvalidArgumentException;

final readonly class MarketHealthPolicy
{
    public function __construct(
        public int $maxQuoteAgeMs,
        public int $maxTradeAgeMs,
        public int $maxOrderBookAgeMs,
        public int $maxReferenceAgeMs,
        public int $maxFutureSkewMs,
        public int $maxProcessingLatencyMs,
    ){
        foreach([
            $this->maxQuoteAgeMs,$this->maxTradeAgeMs,$this->maxOrderBookAgeMs,
            $this->maxReferenceAgeMs,$this->maxFutureSkewMs,$this->maxProcessingLatencyMs,
        ] as $value){
            if($value<1)throw new InvalidArgumentException('Market health thresholds must be positive.');
        }
    }

    public function maxAgeFor(MarketEventType $type):int
    {
        return match($type){
            MarketEventType::Trade=>$this->maxTradeAgeMs,
            MarketEventType::OrderBookSnapshot,MarketEventType::OrderBookDelta=>$this->maxOrderBookAgeMs,
            MarketEventType::ReferencePrice=>$this->maxReferenceAgeMs,
            default=>$this->maxQuoteAgeMs,
        };
    }

    /** @return array<string,int> */
    public function toArray():array{return [
        'max_quote_age_ms'=>$this->maxQuoteAgeMs,
        'max_trade_age_ms'=>$this->maxTradeAgeMs,
        'max_order_book_age_ms'=>$this->maxOrderBookAgeMs,
        'max_reference_age_ms'=>$this->maxReferenceAgeMs,
        'max_future_skew_ms'=>$this->maxFutureSkewMs,
        'max_processing_latency_ms'=>$this->maxProcessingLatencyMs,
    ];}
}
