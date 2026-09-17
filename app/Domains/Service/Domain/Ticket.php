<?php
declare(strict_types=1);

namespace Domains\Service\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Ticket
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $requestId, public string $reference)
    {
        if (trim($id) === '' || trim($requestId) === '' || trim($reference) === '') throw new InvalidArgumentException('Ticket id, request and reference are required.');
    }
}
