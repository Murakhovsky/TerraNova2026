<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class CanonicalMarketEvent extends ValueObject
{
    /**
     * @param list<MarketQualityFlag> $qualityFlags
     */
    public function __construct(
        public string $eventId,
        public MarketSourceId $sourceId,
        public ?VenueId $venueId,
        public InstrumentId $instrumentId,
        public MarketTimestamps $timestamps,
        public ?string $sequence,
        public MarketObservation $observation,
        public array $qualityFlags=[],
        public int $schemaVersion=1,
        public MarketDataMode $mode=MarketDataMode::Live,
        public MarketStatus $marketStatus=MarketStatus::Unknown,
    ){
        if($this->eventId===''||trim($this->eventId)!==$this->eventId||mb_strlen($this->eventId)>190){
            throw new InvalidArgumentException('Canonical market event id is invalid.');
        }
        if($this->sequence!==null&&($this->sequence===''||trim($this->sequence)!==$this->sequence||mb_strlen($this->sequence)>190)){
            throw new InvalidArgumentException('Canonical market sequence is invalid.');
        }
        if($this->schemaVersion<1)throw new InvalidArgumentException('Canonical market schema version must be >= 1.');
        foreach($this->qualityFlags as $flag){
            if(!$flag instanceof MarketQualityFlag)throw new InvalidArgumentException('Canonical market quality flags must be typed.');
        }
        if(count($this->qualityFlags)!==count(array_unique(array_map(static fn(MarketQualityFlag $flag):string=>$flag->value,$this->qualityFlags)))){
            throw new InvalidArgumentException('Canonical market quality flags cannot contain duplicates.');
        }
    }

    public function eventType():MarketEventType{return $this->observation->eventType();}

    public function fingerprint():string
    {
        return hash('sha256',json_encode([
            'source'=>$this->sourceId->value(),
            'venue'=>$this->venueId?->value(),
            'instrument'=>$this->instrumentId->value(),
            'type'=>$this->eventType()->value,
            'source_timestamp'=>$this->timestamps->sourceTimestamp->format('U.u'),
            'sequence'=>$this->sequence,
            'payload'=>$this->observation->toArray(),
            'schema_version'=>$this->schemaVersion,
        ],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @param list<MarketQualityFlag> $flags */
    public function withQualityFlags(array $flags):self
    {
        return new self(
            $this->eventId,$this->sourceId,$this->venueId,$this->instrumentId,$this->timestamps,
            $this->sequence,$this->observation,$flags,$this->schemaVersion,$this->mode,$this->marketStatus
        );
    }

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'event_id'=>$this->eventId,
            'event_type'=>$this->eventType()->value,
            'source_id'=>$this->sourceId->value(),
            'venue_id'=>$this->venueId?->value(),
            'instrument_id'=>$this->instrumentId->value(),
            'source_timestamp'=>$this->timestamps->sourceTimestamp->format(DATE_ATOM),
            'received_timestamp'=>$this->timestamps->receivedTimestamp->format(DATE_ATOM),
            'normalized_timestamp'=>$this->timestamps->processedTimestamp->format(DATE_ATOM),
            'sequence'=>$this->sequence,
            'quality_flags'=>array_map(static fn(MarketQualityFlag $flag):string=>$flag->value,$this->qualityFlags),
            'schema_version'=>$this->schemaVersion,
            'mode'=>$this->mode->value,
            'market_status'=>$this->marketStatus->value,
            'payload'=>$this->observation->toArray(),
            'fingerprint'=>$this->fingerprint(),
        ];
    }
}
