<?php
declare(strict_types=1);

namespace Domains\Procurement\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\Money;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Quote
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $supplierId, public string $purchaseRequestId, public Money $total)
    {
        if (trim($id) === '' || trim($supplierId) === '' || trim($purchaseRequestId) === '') throw new InvalidArgumentException('Quote id, supplier and purchase request are required.');
    }
}
