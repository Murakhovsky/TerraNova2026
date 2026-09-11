<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesAssignmentAuthorityInterface
{
    public function assertCanAssign(
        string $organizationId,
        string $actorType,
        string $actorId,
        int $ownerId,
    ): void;
}
