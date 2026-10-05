<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;

final readonly class InstrumentCreated extends AbstractCapitalMarketsEvent
{
    public const TYPE='capital_markets.instrument.created.v1';

    /** @param array<string,mixed> $payload */
    public function __construct(string $eventId,DateTimeImmutable $occurredAt,string $organizationId,public InstrumentId $instrumentId,array $payload=[])
    {
        parent::__construct($eventId,$occurredAt,$organizationId,$instrumentId->value(),$payload,1);
    }

    public function eventName():string{return self::TYPE;}
}
