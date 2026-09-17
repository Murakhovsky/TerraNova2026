<?php
declare(strict_types=1);

namespace Domains\Procurement\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\Money;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Order
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $supplierId, public string $reference, public Money $total)
    {
        if (trim($id) === '' || trim($supplierId) === '' || trim($reference) === '') throw new InvalidArgumentException('Order id, supplier and reference are required.');
    }
}
