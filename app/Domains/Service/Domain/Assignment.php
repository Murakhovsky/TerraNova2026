<?php
declare(strict_types=1);

namespace Domains\Service\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Assignment
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $ticketId, public string $assigneeId)
    {
        if (trim($id) === '' || trim($ticketId) === '' || trim($assigneeId) === '') throw new InvalidArgumentException('Assignment id, ticket and assignee are required.');
    }
}
