<?php
declare(strict_types=1);

namespace Domains\Property\Application\Service;

use Domains\Property\Application\Contract\PropertyInventoryCommandInterface;

final readonly class CanonicalPropertyInventoryCommands implements PropertyInventoryCommandInterface
{
    public function __construct(private PropertyCanonicalRuntimeService $runtime) {}

    public function reserve(
        string $organizationId,
        string $inventoryId,
        array $input,
        ?string $actorId = null,
        ?string $correlationId = null,
    ): array {
        return $this->runtime->reserveInventory($organizationId, $inventoryId, $input, $actorId, $correlationId);
    }

    public function changeStatus(
        string $organizationId,
        string $inventoryId,
        string $status,
        ?string $reason = null,
        ?string $actorId = null,
        ?string $correlationId = null,
    ): array {
        return $this->runtime->changeInventoryStatus(
            $organizationId,
            $inventoryId,
            $status,
            $reason,
            $actorId,
            $correlationId,
        );
    }
}
