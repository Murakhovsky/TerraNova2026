<?php
declare(strict_types=1);

namespace Domains\Service\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class ServiceCase
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $subject)
    {
        if (trim($id) === '' || trim($subject) === '') throw new InvalidArgumentException('Service case id and subject are required.');
    }
}
