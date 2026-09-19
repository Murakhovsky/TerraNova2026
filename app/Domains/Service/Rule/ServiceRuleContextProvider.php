<?php
declare(strict_types=1);

namespace Domains\Service\Rule;

use Domains\Service\Automation\Event\ServiceEventType;
use InvalidArgumentException;
use Kernel\Event\DomainEvent;
use Kernel\Rule\Contract\RuleContextProviderInterface;

final readonly class ServiceRuleContextProvider implements RuleContextProviderInterface
{
    public function contextFor(DomainEvent $event): array
    {
        if (!in_array($event->type, ServiceEventType::values(), true)) {
            throw new InvalidArgumentException('Unsupported Service event type: ' . $event->type);
        }

        return [
            'organization_id' => $event->organizationId,
            'service_aggregate_id' => $event->aggregateId,
            'event_type' => $event->type,
            'service' => $event->payload,
            'event' => [
                'type' => $event->type,
                'aggregate_type' => $event->aggregateType,
                'aggregate_id' => $event->aggregateId,
                'payload' => $event->payload,
            ],
        ];
    }
}
