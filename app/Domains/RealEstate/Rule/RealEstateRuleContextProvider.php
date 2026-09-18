<?php
declare(strict_types=1);

namespace Domains\RealEstate\Rule;

use Domains\RealEstate\Automation\Event\RealEstateEventType;
use InvalidArgumentException;
use Kernel\Event\DomainEvent;
use Kernel\Rule\Contract\RuleContextProviderInterface;

final readonly class RealEstateRuleContextProvider implements RuleContextProviderInterface
{
    public function contextFor(DomainEvent $event): array
    {
        if (!in_array($event->type, RealEstateEventType::values(), true)) {
            throw new InvalidArgumentException('Unsupported RealEstate event type: ' . $event->type);
        }

        return [
            'organization_id' => $event->organizationId,
            'brokerage_case_id' => $event->aggregateId,
            'event_type' => $event->type,
            'real_estate' => $event->payload,
            'event' => [
                'type' => $event->type,
                'aggregate_type' => $event->aggregateType,
                'aggregate_id' => $event->aggregateId,
                'payload' => $event->payload,
            ],
        ];
    }
}
