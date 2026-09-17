<?php
declare(strict_types=1);

namespace Domains\Finance\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\Money;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Transaction
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $accountId, public Money $amount, public DateTimeImmutable $occurredAt)
    {
        if (trim($id) === '' || trim($accountId) === '') throw new InvalidArgumentException('Transaction id and account are required.');
    }
}
