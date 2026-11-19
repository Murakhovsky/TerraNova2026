<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Bootstrap;

use Kernel\Module\DomainModuleInterface;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use Domains\ProductManagement\Rule\ProductManagementRuleContextProvider;

/** Business event owner, not an independent scheduler or executor. */
final readonly class ProductManagementDomainModule implements DomainModuleInterface, EventOwningModuleInterface
{
    public function name(): string { return 'product_management'; }

    public function ruleContextProvider(): RuleContextProviderInterface
    {
        return new ProductManagementRuleContextProvider();
    }

    public function eventTypes(): array
    {
        return [
            'product_management.product.created',
            'product_management.variant.created',
            'product_management.variant.updated',
            'product_management.variant.archived',
            'product_management.category.created',
            'product_management.category.assigned',
            'product_management.location.created',
            'product_management.stock.movement_posted',
            'product_management.price.updated',
        ];
    }
}
