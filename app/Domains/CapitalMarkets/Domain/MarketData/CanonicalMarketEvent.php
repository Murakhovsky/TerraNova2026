<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;

final readonly class CanonicalMarketEvent
{
    /** @param list<MarketQualityFlag> $qualityFlags */
    public function __construct(
        public string $eventId,
        public MarketEventType $eventType,
        public MarketSourceId $sourceId,
        public ?VenueId $venueId,
        public InstrumentId $instrumentId,
        public DateTimeImmutable $sourceTimestamp,
        public DateTimeImmutable $receivedTimestamp,
        public DateTimeImmutable $normalizedTimestamp,
        public ?string $sequence,
        public SequenceSemantics $sequenceSemantics,
        public array $qualityFlags,
        public int $schemaVersion,
        public MarketDataMode $mode,
        public MarketStatus $marketStatus,
        public MarketEventPayload $payload,
    ){
        if(trim($this->eventId)==='')throw new InvalidArgumentException('Canonical market event id is required.');
        if($this->schemaVersion<1)throw new InvalidArgumentException('Canonical market event schema version must be positive.');
        foreach($this->qualityFlags as $flag)if(!$flag instanceof MarketQualityFlag)throw new InvalidArgumentException('Quality flags must be typed.');
    }

    public function fingerprint():string
    {
        return hash('sha256',json_encode([
            $this->sourceId->value(),$this->venueId?->value(),$this->instrumentId->value(),$this->eventType->value,
            $this->sourceTimestamp->format('U.u'),$this->sequence,$this->payload->toArray(),
        ],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
    }

    public function toArray():array{return [
        'event_id'=>$this->eventId,'event_type'=>$this->eventType->value,
        'source_id'=>$this->sourceId->value(),'venue_id'=>$this->venueId?->value(),'instrument_id'=>$this->instrumentId->value(),
        'source_timestamp'=>$this->sourceTimestamp->format(DATE_ATOM),
        'received_timestamp'=>$this->receivedTimestamp->format(DATE_ATOM),
        'normalized_timestamp'=>$this->normalizedTimestamp->format(DATE_ATOM),
        'sequence'=>$this->sequence,'sequence_semantics'=>$this->sequenceSemantics->value,
        'quality_flags'=>array_map(static fn(MarketQualityFlag $flag):string=>$flag->value,$this->qualityFlags),
        'schema_version'=>$this->schemaVersion,'mode'=>$this->mode->value,'market_status'=>$this->marketStatus->value,
        'payload'=>$this->payload->toArray(),
    ];}
}
