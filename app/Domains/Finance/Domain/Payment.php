<?php
declare(strict_types=1);

namespace Domains\Finance\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\Money;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Payment
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $invoiceId, public Money $amount)
    {
        if (trim($id) === '' || trim($invoiceId) === '') throw new InvalidArgumentException('Payment id and invoice are required.');
    }
}
