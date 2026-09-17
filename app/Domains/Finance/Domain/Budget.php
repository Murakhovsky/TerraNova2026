<?php
declare(strict_types=1);

namespace Domains\Finance\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\Money;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Budget
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $name, public Money $limit)
    {
        if (trim($id) === '' || trim($name) === '') throw new InvalidArgumentException('Budget id and name are required.');
    }
}
