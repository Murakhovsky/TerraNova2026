<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketSubscription extends ValueObject
{
    public function __construct(
        public MarketSubscriptionId $id,
        public MarketSourceId $sourceId,
        public ?VenueId $venueId,
        public InstrumentId $instrumentId,
        public MarketEventType $dataType,
        public MarketSubscriptionStatus $status,
        public DateTimeImmutable $subscribedAt,
        public ?DateTimeImmutable $lastEventAt=null,
    ){}
}
