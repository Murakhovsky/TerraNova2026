<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

use DateTimeImmutable;

final readonly class MarketDataNormalizationFailed extends AbstractCapitalMarketsEvent
{
    public const TYPE='capital_markets.market_data.normalization_failed.v1';

    /** @param array<string,mixed> $payload */
    public function __construct(string $eventId,DateTimeImmutable $occurredAt,string $organizationId,string $rawEventId,array $payload=[])
    {
        parent::__construct($eventId,$occurredAt,$organizationId,$rawEventId,$payload,1);
    }

    public function eventName():string{return self::TYPE;}
}
