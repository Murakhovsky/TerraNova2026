<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Bootstrap;

use Kernel\Module\DomainModuleInterface;

/**
 * Product Management owns the merchandise and stock state; the Kernel
 * owns workflow, policy, execution and tenant activation.
 */
final readonly class ProductManagementDomainModule implements DomainModuleInterface
{
    public function name(): string
    {
        return 'product_management';
    }
}
