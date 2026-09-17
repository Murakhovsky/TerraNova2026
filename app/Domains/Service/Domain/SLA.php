<?php
declare(strict_types=1);

namespace Domains\Service\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class SLA
{
    public function __construct(public string $id, public OrganizationId $organizationId, public string $name, public int $responseMinutes, public int $resolutionMinutes)
    {
        if (trim($id) === '' || trim($name) === '' || $responseMinutes < 0 || $resolutionMinutes < $responseMinutes) throw new InvalidArgumentException('Invalid SLA definition.');
    }
}
