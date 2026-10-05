<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

final readonly class VenueInstrumentRegistered extends AbstractCapitalMarketsEvent
{
    public const TYPE='capital_markets.venue_instrument.registered.v1';

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $organizationId,
        public VenueId $venueId,
        public InstrumentId $instrumentId,
        public string $venueSymbol,
    ){
        parent::__construct(
            $eventId,$occurredAt,$organizationId,$venueId->value().'|'.$instrumentId->value(),
            ['venue_id'=>$venueId->value(),'instrument_id'=>$instrumentId->value(),'venue_symbol'=>$venueSymbol],1
        );
    }

    public function eventName():string{return self::TYPE;}
}
