<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;

final readonly class MarketState
{
    /**
     * @param array<string,string> $eventWatermarks
     * @param array<string,string> $sequenceWatermarks
     * @param array<string,string> $eventFingerprints
     */
    public function __construct(
        public MarketSourceId $sourceId,
        public VenueId $venueId,
        public InstrumentId $instrumentId,
        public ?MarketQuote $quote,
        public ?MarketTrade $lastTrade,
        public ?MarketOrderBook $orderBook,
        public ?MarketVolume $volume,
        public MarketStatus $marketStatus,
        public MarketDataMode $mode,
        public DateTimeImmutable $sourceTimestamp,
        public DateTimeImmutable $updatedAt,
        public MarketDataQualityAssessment $quality,
        public int $stateVersion,
        public array $eventWatermarks=[],
        public array $sequenceWatermarks=[],
        public array $eventFingerprints=[],
    ){
        if($this->stateVersion<1)throw new InvalidArgumentException('Market state version must be positive.');
    }

    public function key():string{return $this->venueId->value().'|'.$this->instrumentId->value();}

    public function toArray():array{return [
        'source_id'=>$this->sourceId->value(),
        'venue_id'=>$this->venueId->value(),
        'instrument_id'=>$this->instrumentId->value(),
        'quote'=>$this->quote?->toArray(),
        'last_trade'=>$this->lastTrade?->toArray(),
        'order_book'=>$this->orderBook?->toArray(),
        'volume'=>$this->volume?->toArray(),
        'market_status'=>$this->marketStatus->value,
        'mode'=>$this->mode->value,
        'source_timestamp'=>$this->sourceTimestamp->format(DATE_ATOM),
        'updated_at'=>$this->updatedAt->format(DATE_ATOM),
        'quality'=>$this->quality->toArray(),
        'state_version'=>$this->stateVersion,
    ];}
}
