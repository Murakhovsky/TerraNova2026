<?php
declare(strict_types=1);

namespace Domains\Service\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Resolution
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $ticketId, public string $summary)
    {
        if (trim($id) === '' || trim($ticketId) === '' || trim($summary) === '') throw new InvalidArgumentException('Resolution id, ticket and summary are required.');
    }
}
