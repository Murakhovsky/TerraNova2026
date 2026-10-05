<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;

final readonly class RawMarketEvent
{
    /** @param array<string,scalar|null> $transportMetadata */
    public function __construct(
        public string $eventId,
        public MarketSourceId $sourceId,
        public ?VenueId $venueId,
        public string $externalInstrument,
        public string $providerEventType,
        public ?DateTimeImmutable $providerTimestamp,
        public DateTimeImmutable $receivedAt,
        public ?string $sequence,
        public string $rawPayload,
        public array $transportMetadata=[],
        public MarketDataMode $mode=MarketDataMode::Live,
    ){
        if(trim($this->eventId)==='')throw new InvalidArgumentException('Raw market event id is required.');
        if(trim($this->externalInstrument)==='')throw new InvalidArgumentException('Raw market event instrument is required.');
        if(trim($this->providerEventType)==='')throw new InvalidArgumentException('Raw market event type is required.');
        if($this->sequence!==null&&trim($this->sequence)==='')throw new InvalidArgumentException('Raw market event sequence is invalid.');
        if($this->rawPayload==='')throw new InvalidArgumentException('Raw market payload must not be empty.');
    }

    public function payloadBytes():int{return strlen($this->rawPayload);}
}
