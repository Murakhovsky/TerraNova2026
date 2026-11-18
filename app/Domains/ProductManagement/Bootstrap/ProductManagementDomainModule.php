<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Bootstrap;

use Kernel\Module\DomainModuleInterface;
use Kernel\Module\Contract\EventOwningModuleInterface;

/** Business event owner, not an independent scheduler or executor. */
final readonly class ProductManagementDomainModule implements DomainModuleInterface, EventOwningModuleInterface
{
    public function name(): string { return 'product_management'; }

    public function eventTypes(): array
    {
        return [
            'product_management.product.created',
            'product_management.location.created',
            'product_management.stock.movement_posted',
            'product_management.price.updated',
        ];
    }
}
