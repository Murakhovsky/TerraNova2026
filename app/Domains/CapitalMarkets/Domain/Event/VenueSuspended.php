<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

final readonly class VenueSuspended extends AbstractCapitalMarketsEvent
{
    public const TYPE='capital_markets.venue.suspended.v1';

    public function __construct(string $eventId,DateTimeImmutable $occurredAt,string $organizationId,public VenueId $venueId)
    {
        parent::__construct($eventId,$occurredAt,$organizationId,$venueId->value(),['status'=>'SUSPENDED'],1);
    }

    public function eventName():string{return self::TYPE;}
}
