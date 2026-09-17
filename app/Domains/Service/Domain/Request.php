<?php
declare(strict_types=1);

namespace Domains\Service\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Request
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $caseId, public string $summary)
    {
        if (trim($id) === '' || trim($caseId) === '' || trim($summary) === '') throw new InvalidArgumentException('Service request id, case and summary are required.');
    }
}
