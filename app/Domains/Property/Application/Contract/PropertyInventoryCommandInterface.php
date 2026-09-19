<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyInventoryCommandInterface
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function reserve(
        string $organizationId,
        string $inventoryId,
        array $input,
        ?string $actorId = null,
        ?string $correlationId = null,
    ): array;

    /** @return array<string,mixed> */
    public function changeStatus(
        string $organizationId,
        string $inventoryId,
        string $status,
        ?string $reason = null,
        ?string $actorId = null,
        ?string $correlationId = null,
    ): array;
}
