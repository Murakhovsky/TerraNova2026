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
            'product_management.translation.upserted',
            'product_management.media.attached',
            'product_management.media.detached',
            'product_management.variant.created',
            'product_management.variant.updated',
            'product_management.variant.archived',
            'product_management.category.created',
            'product_management.category.assigned',
            'product_management.location.created',
            'product_management.stock.movement_posted',
            'product_management.stock.transferred',
            'product_management.price.updated',
            'product_management.stock.reserved',
            'product_management.stock.released',
            'product_management.stock.count_applied',
            'product_management.price_list.created',
            'product_management.price_list.updated',
            'product_management.price_list.archived',
            'product_management.price_list.entry_updated',
            'product_management.price_list.entry_removed',
            'product_management.price_list.tier_changed',
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
