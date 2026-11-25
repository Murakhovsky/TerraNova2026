<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class ManageSalesLeadStateCommand implements CommandInterface
{
    public function __construct(public OrganizationId $organizationId,public int $actorId,public int $leadId,
        public string $expectedStatus,public string $targetStatus,public string $idempotencyKey,public string $correlationId) {}
}
