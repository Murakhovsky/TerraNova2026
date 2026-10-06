<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketState extends ValueObject
{
    public function __construct(
        public InstrumentId $instrumentId,
        public VenueId $venueId,
        public MarketSourceId $sourceId,
        public ?MarketTrade $lastTrade,
        public ?MarketQuote $bestQuote,
        public ?MarketOrderBook $orderBook,
        public ?Decimal $volume,
        public MarketStatus $marketStatus,
        public DateTimeImmutable $sourceTimestamp,
        public DateTimeImmutable $updatedAt,
        public MarketDataQualityAssessment $quality,
        public int $stateVersion,
        public ?string $lastSequence,
        public string $lastEventFingerprint,
        public MarketDataMode $mode=MarketDataMode::Live,
        public ?MarketValueObservation $fundingRate=null,
        public ?MarketValueObservation $openInterest=null,
        public ?MarketValueObservation $markPrice=null,
        public ?MarketValueObservation $indexPrice=null,
    ){
        if($this->stateVersion<1)throw new InvalidArgumentException('Market state version must be positive.');
        if($this->lastEventFingerprint===''||strlen($this->lastEventFingerprint)!==64){
            throw new InvalidArgumentException('Market state event fingerprint must be SHA-256.');
        }
        if($this->volume?->isNegative())throw new InvalidArgumentException('Market state volume cannot be negative.');
        foreach([
            MarketEventType::FundingRate->value=>$this->fundingRate,
            MarketEventType::OpenInterest->value=>$this->openInterest,
            MarketEventType::MarkPrice->value=>$this->markPrice,
            MarketEventType::IndexPrice->value=>$this->indexPrice,
        ] as $type=>$observation){
            if($observation!==null&&$observation->eventType()->value!==$type){
                throw new InvalidArgumentException('Market state scalar observation type mismatch.');
            }
        }
    }

    public function key():string{return $this->venueId->value().'|'.$this->instrumentId->value();}

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'instrument_id'=>$this->instrumentId->value(),
            'venue_id'=>$this->venueId->value(),
            'source_id'=>$this->sourceId->value(),
            'last_trade'=>$this->lastTrade?->toArray(),
            'best_quote'=>$this->bestQuote?->toArray(),
            'mid_price'=>$this->bestQuote?->midPrice()->value(),
            'spread_absolute'=>$this->bestQuote?->spreadAbsolute()->value(),
            'spread_bps'=>$this->bestQuote?->spreadBps()->value(),
            'order_book'=>$this->orderBook?->toArray(),
            'volume'=>$this->volume?->value(),
            'funding_rate'=>$this->fundingRate?->toArray(),
            'open_interest'=>$this->openInterest?->toArray(),
            'mark_price'=>$this->markPrice?->toArray(),
            'index_price'=>$this->indexPrice?->toArray(),
            'market_status'=>$this->marketStatus->value,
            'source_timestamp'=>$this->sourceTimestamp->format(DATE_ATOM),
            'updated_at'=>$this->updatedAt->format(DATE_ATOM),
            'latency'=>[
                'ingestion_ms'=>$this->quality->ingestionLatencyMilliseconds,
                'processing_ms'=>$this->quality->processingLatencyMilliseconds,
                'event_age_ms'=>$this->quality->eventAgeMilliseconds,
            ],
            'quality_status'=>$this->quality->status->value,
            'quality_score'=>$this->quality->score,
            'quality_flags'=>array_map(static fn(MarketQualityFlag $flag):string=>$flag->value,$this->quality->flags),
            'reference_deviation_bps'=>$this->quality->referenceDeviationBps?->value(),
            'trust_status'=>$this->quality->status->value,
            'state_version'=>$this->stateVersion,
            'last_sequence'=>$this->lastSequence,
            'last_event_fingerprint'=>$this->lastEventFingerprint,
            'mode'=>$this->mode->value,
        ];
    }
}
