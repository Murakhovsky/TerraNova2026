<?php
declare(strict_types=1);

namespace Domains\Procurement\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class PurchaseRequest
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $description)
    {
        if (trim($id) === '' || trim($description) === '') throw new InvalidArgumentException('Purchase request id and description are required.');
    }
}
