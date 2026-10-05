<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

final readonly class VenueCreated extends AbstractCapitalMarketsEvent
{
    public const TYPE='capital_markets.venue.created.v1';

    /** @param array<string,mixed> $payload */
    public function __construct(string $eventId,DateTimeImmutable $occurredAt,string $organizationId,public VenueId $venueId,array $payload=[])
    {
        parent::__construct($eventId,$occurredAt,$organizationId,$venueId->value(),$payload,1);
    }

    public function eventName():string{return self::TYPE;}
}
