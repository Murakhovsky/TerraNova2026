<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipType;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;

final readonly class EconomicRelationshipDefined extends AbstractCapitalMarketsEvent
{
    public const TYPE = 'capital_markets.relationship.defined';

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        public InstrumentId $from,
        public InstrumentId $to,
        public EconomicRelationshipType $relationshipType,
    ) {
        parent::__construct($eventId, $occurredAt);
    }

    public function eventName(): string
    {
        return self::TYPE;
    }
}
