<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

final readonly class MarketSubscription
{
    public function __construct(
        public MarketSourceId $sourceId,
        public ?VenueId $venueId,
        public InstrumentId $instrumentId,
        public MarketEventType $dataType,
        public MarketSubscriptionStatus $status,
        public ?DateTimeImmutable $subscribedAt=null,
        public ?DateTimeImmutable $lastEventAt=null,
    ){}

    public function key():string
    {
        return $this->sourceId->value().'|'.($this->venueId?->value()??'reference').'|'.$this->instrumentId->value().'|'.$this->dataType->value;
    }
}
