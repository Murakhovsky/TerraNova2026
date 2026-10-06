<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;
use Domains\CapitalMarkets\Domain\MarketData\MarketQualityFlag;
use Domains\CapitalMarkets\Domain\MarketData\MarketQuote;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrade;
use Domains\CapitalMarkets\Domain\MarketData\MarketValueObservation;

final readonly class MarketStateEngine
{
    public function __construct(private OrderBookRebuilder $books){}

    public function apply(
        CanonicalMarketEvent $event,
        MarketDataQualityAssessment $quality,
        ?MarketState $previous,
        MarketStatus $marketStatus=MarketStatus::Unknown,
    ):MarketState{
        if($event->venueId===null)throw new DomainException('Trading MarketState requires venue_id.');

        if($previous!==null&&($this->has($quality,MarketQualityFlag::Duplicate)||$this->has($quality,MarketQualityFlag::OutOfOrder))){
            return $previous;
        }

        $lastTrade=$previous?->lastTrade;
        $bestQuote=$previous?->bestQuote;
        $orderBook=$previous?->orderBook;
        $volume=$previous?->volume;

        if($event->observation instanceof MarketTrade)$lastTrade=$event->observation;
        if($event->observation instanceof MarketQuote)$bestQuote=$event->observation;

        if($event->observation instanceof MarketOrderBook){
            if($event->eventType()===MarketEventType::OrderBookSnapshot){
                $orderBook=$event->observation;
            }elseif($this->has($quality,MarketQualityFlag::SequenceGap)||$this->has($quality,MarketQualityFlag::OrderBookInvalid)){
                $orderBook=null;
            }elseif($orderBook!==null){
                $orderBook=$this->books->apply($orderBook,$event->observation);
            }else{
                throw new DomainException('Order-book delta cannot be applied without a snapshot.');
            }
        }

        if($event->observation instanceof MarketValueObservation&&$event->eventType()===MarketEventType::Volume){
            $volume=$event->observation->value;
        }

        return new MarketState(
            $event->instrumentId,
            $event->venueId,
            $event->sourceId,
            $lastTrade,
            $bestQuote,
            $orderBook,
            $volume,
            $marketStatus,
            $event->timestamps->sourceTimestamp,
            $event->timestamps->processedTimestamp,
            $quality,
            ($previous?->stateVersion??0)+1,
            $event->sequence??$previous?->lastSequence,
            $event->fingerprint(),
            $event->mode,
        );
    }

    private function has(MarketDataQualityAssessment $quality,MarketQualityFlag $flag):bool
    {
        return in_array($flag,$quality->flags,true);
    }
}
