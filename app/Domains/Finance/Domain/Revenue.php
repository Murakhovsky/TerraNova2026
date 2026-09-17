<?php
declare(strict_types=1);

namespace Domains\Finance\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\Money;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Revenue
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $source, public Money $amount)
    {
        if (trim($id) === '' || trim($source) === '') throw new InvalidArgumentException('Revenue id and source are required.');
    }
}
