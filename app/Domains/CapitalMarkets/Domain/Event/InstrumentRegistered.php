<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;

final readonly class InstrumentRegistered extends AbstractCapitalMarketsEvent
{
    public const TYPE = 'capital_markets.instrument.registered';

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        public InstrumentId $instrumentId,
    ) {
        parent::__construct($eventId, $occurredAt);
    }

    public function eventName(): string
    {
        return self::TYPE;
    }
}
