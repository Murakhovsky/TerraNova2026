<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Rule;

use InvalidArgumentException;
use Kernel\Event\DomainEvent;
use Kernel\Rule\Contract\RuleContextProviderInterface;

/**
 * Read-only event facts for deterministic Kernel Rule evaluation.
 * No cross-domain persistence, external API or mutation permissions.
 */
final readonly class ProductManagementRuleContextProvider implements RuleContextProviderInterface
{
    public function contextFor(DomainEvent $event): array
    {
        if (!in_array($event->type, [
            'product_management.product.created',
            'product_management.variant.created',
            'product_management.location.created',
            'product_management.stock.movement_posted',
            'product_management.price.updated',
        ], true)) {
            throw new InvalidArgumentException('Unsupported Product Management event type.');
        }
        return [
            'organization_id'=>$event->organizationId,
            'event_type'=>$event->type,
            'aggregate_type'=>$event->aggregateType,
            'aggregate_id'=>$event->aggregateId,
            'product_management'=>$event->payload,
            'event'=>[
                'type'=>$event->type,
                'aggregate_type'=>$event->aggregateType,
                'aggregate_id'=>$event->aggregateId,
                'payload'=>$event->payload,
            ],
        ];
    }
}
