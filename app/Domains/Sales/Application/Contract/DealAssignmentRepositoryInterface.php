<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use Domains\Sales\Application\DTO\OperationResult;

interface DealAssignmentRepositoryInterface
{
    public function assignOwner(string $organizationId, string $dealId, int $ownerId): OperationResult;
}
