<?php
declare(strict_types=1);

namespace Domains\Property\Rule;

use Domains\Property\Automation\Event\PropertyEventType;
use InvalidArgumentException;
use Kernel\Event\DomainEvent;
use Kernel\Rule\Contract\RuleContextProviderInterface;

final readonly class PropertyRuleContextProvider implements RuleContextProviderInterface
{
    public function contextFor(DomainEvent $event): array
    {
        if (!in_array($event->type, PropertyEventType::values(), true)) {
            throw new InvalidArgumentException('Unsupported Property event type: ' . $event->type);
        }

        return [
            'organization_id' => $event->organizationId,
            'asset_id' => $event->aggregateId,
            'event_type' => $event->type,
            'property' => $event->payload,
            'event' => [
                'type' => $event->type,
                'aggregate_type' => $event->aggregateType,
                'aggregate_id' => $event->aggregateId,
                'payload' => $event->payload,
            ],
        ];
    }
}
