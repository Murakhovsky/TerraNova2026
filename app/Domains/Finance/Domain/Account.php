<?php
declare(strict_types=1);

namespace Domains\Finance\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Account
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $name, public string $currency)
    {
        if (trim($id) === '' || trim($name) === '' || preg_match('/^[A-Z]{3}$/', strtoupper($currency)) !== 1) throw new InvalidArgumentException('Invalid finance account.');
    }
}
