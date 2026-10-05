<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;

final readonly class InstrumentSuspended extends AbstractCapitalMarketsEvent
{
    public const TYPE='capital_markets.instrument.suspended.v1';

    public function __construct(string $eventId,DateTimeImmutable $occurredAt,string $organizationId,public InstrumentId $instrumentId)
    {
        parent::__construct($eventId,$occurredAt,$organizationId,$instrumentId->value(),['status'=>'SUSPENDED'],1);
    }

    public function eventName():string{return self::TYPE;}
}
