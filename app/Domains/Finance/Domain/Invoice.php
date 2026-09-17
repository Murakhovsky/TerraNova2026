<?php
declare(strict_types=1);

namespace Domains\Finance\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\Money;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Invoice
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $reference, public Money $total)
    {
        if (trim($id) === '' || trim($reference) === '') throw new InvalidArgumentException('Invoice id and reference are required.');
    }
}
