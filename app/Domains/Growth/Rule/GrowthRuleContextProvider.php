<?php
declare(strict_types=1);

namespace Domains\Growth\Rule;

use Domains\Growth\Automation\Event\GrowthEventType;
use InvalidArgumentException;
use Kernel\Event\DomainEvent;
use Kernel\Rule\Contract\RuleContextProviderInterface;

final readonly class GrowthRuleContextProvider implements RuleContextProviderInterface
{
    public function contextFor(DomainEvent $event): array
    {
        if (!in_array($event->type, GrowthEventType::values(), true)) {
            throw new InvalidArgumentException('Unsupported Growth event type: ' . $event->type);
        }

        return [
            'organization_id' => $event->organizationId,
            'growth_aggregate_id' => $event->aggregateId,
            'event_type' => $event->type,
            'growth' => $event->payload,
            'event' => [
                'type' => $event->type,
                'aggregate_type' => $event->aggregateType,
                'aggregate_id' => $event->aggregateId,
                'payload' => $event->payload,
            ],
        ];
    }
}
