<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ResearchLifecycleEvent extends AbstractCapitalMarketsEvent
{
    private const TYPES=[
        'capital_markets.research.hypothesis_created.v1',
        'capital_markets.research.hypothesis_updated.v1',
        'capital_markets.research.hypothesis_rejected.v1',
        'capital_markets.research.hypothesis_reopened.v1',
        'capital_markets.research.experiment_created.v1',
        'capital_markets.research.experiment_started.v1',
        'capital_markets.research.experiment_completed.v1',
        'capital_markets.research.experiment_invalidated.v1',
        'capital_markets.research.backtest_completed.v1',
        'capital_markets.research.oos_completed.v1',
        'capital_markets.research.paper_completed.v1',
        'capital_markets.research.strategy_version_created.v1',
        'capital_markets.research.strategy_scorecard_updated.v1',
        'capital_markets.research.strategy_promotion_requested.v1',
        'capital_markets.research.strategy_promoted.v1',
        'capital_markets.research.strategy_demoted.v1',
        'capital_markets.research.strategy_rejected.v1',
    ];

    /** @param array<string,mixed> $payload */
    public function __construct(
        private string $type,
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $organizationId,
        string $aggregateId,
        array $payload=[],
    ){
        if(!in_array($type,self::TYPES,true))throw new InvalidArgumentException('Unsupported Capital Markets research event type.');
        parent::__construct($eventId,$occurredAt,$organizationId,$aggregateId,$payload,1);
    }

    public function eventName():string{return $this->type;}
}
