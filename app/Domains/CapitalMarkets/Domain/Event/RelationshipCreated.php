<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\RelationshipId;

final readonly class RelationshipCreated extends AbstractCapitalMarketsEvent
{
    public const TYPE='capital_markets.relationship.created.v1';

    /** @param array<string,mixed> $payload */
    public function __construct(string $eventId,DateTimeImmutable $occurredAt,string $organizationId,public RelationshipId $relationshipId,array $payload=[])
    {
        parent::__construct($eventId,$occurredAt,$organizationId,$relationshipId->value(),$payload,1);
    }

    public function eventName():string{return self::TYPE;}
}
