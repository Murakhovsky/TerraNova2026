<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

final readonly class VenueRegistered extends AbstractCapitalMarketsEvent
{
    public const TYPE = 'capital_markets.venue.registered';

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        public VenueId $venueId,
    ) {
        parent::__construct($eventId, $occurredAt);
    }

    public function eventName(): string
    {
        return self::TYPE;
    }
}
