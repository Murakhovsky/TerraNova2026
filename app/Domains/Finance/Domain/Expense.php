<?php
declare(strict_types=1);

namespace Domains\Finance\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\Money;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Expense
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $category, public Money $amount)
    {
        if (trim($id) === '' || trim($category) === '') throw new InvalidArgumentException('Expense id and category are required.');
    }
}
