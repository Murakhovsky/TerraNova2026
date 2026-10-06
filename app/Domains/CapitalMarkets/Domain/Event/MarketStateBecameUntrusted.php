<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

use DateTimeImmutable;

final readonly class MarketStateBecameUntrusted extends AbstractCapitalMarketsEvent
{
    public const TYPE='capital_markets.market_state.untrusted.v1';

    /** @param array<string,mixed> $payload */
    public function __construct(string $eventId,DateTimeImmutable $occurredAt,string $organizationId,string $marketKey,array $payload=[])
    {
        parent::__construct($eventId,$occurredAt,$organizationId,$marketKey,$payload,1);
    }

    public function eventName():string{return self::TYPE;}
}
