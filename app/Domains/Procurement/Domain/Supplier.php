<?php
declare(strict_types=1);

namespace Domains\Procurement\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Supplier
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $name)
    {
        if (trim($id) === '' || trim($name) === '') throw new InvalidArgumentException('Supplier id and name are required.');
    }
}
