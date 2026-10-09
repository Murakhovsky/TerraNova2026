<?php

declare(strict_types=1);

namespace App\Web\CapitalMarkets;

use App\Web\Experience\Realtime\RealtimeStreamPublisher;
use App\Web\Experience\Realtime\RealtimeTopicFactory;
use Kernel\Event\Contract\DurableEventConsumerInterface;
use Kernel\Event\DomainEvent;

final readonly class CapitalMarketsRealtimeEventConsumer implements DurableEventConsumerInterface
{
    private const WORKSPACE_ID = 'capital-markets';

    public function __construct(
        private RealtimeTopicFactory $topics,
        private RealtimeStreamPublisher $publisher,
    ) {
    }

    public function consumerName(): string
    {
        return 'capital-markets.decision-workspace-realtime.v1';
    }

    public function handle(DomainEvent $event): void
    {
        if (!$this->isRelevant($event->type)) {
            return;
        }

        $this->publisher->publish(
            $this->topics->workspace($event->organizationId, self::WORKSPACE_ID),
            'experience/realtime/streams/capital_markets_decision_signal.stream.html.twig',
            [
                'event' => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'aggregate_type' => $event->aggregateType,
                    'aggregate_id' => $event->aggregateId,
                    'occurred_at' => $event->occurredAt,
                ],
            ],
        );
    }

    private function isRelevant(string $eventType): bool
    {
        foreach ([
            'capital_markets.market_state.',
            'capital_markets.portfolio.',
            'capital_markets.allocation.',
            'capital_markets.strategy.',
            'capital_markets.capital.',
            'capital_markets.rebalance.',
            'capital_markets.exposure.',
            'capital_markets.concentration.',
            'capital_markets.margin.',
            'capital_markets.research.',
            'capital_markets.opportunity.',
            'capital_markets.execution.',
        ] as $prefix) {
            if (str_starts_with($eventType, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
