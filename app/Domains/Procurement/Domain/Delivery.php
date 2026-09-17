<?php
declare(strict_types=1);

namespace Domains\Procurement\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Delivery
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $orderId, public string $reference)
    {
        if (trim($id) === '' || trim($orderId) === '' || trim($reference) === '') throw new InvalidArgumentException('Delivery id, order and reference are required.');
    }
}
