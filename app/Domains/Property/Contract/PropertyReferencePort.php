<?php
declare(strict_types=1);

namespace Domains\Property\Contract;

interface PropertyReferencePort
{
    /** @return array<string,mixed>|null */
    public function getPropertyReference(string $organizationId, string|int $reference): ?array;

    /** @return array<string,mixed>|null */
    public function getInventorySnapshot(string $organizationId, string $inventoryId): ?array;

    /** @return list<array<string,mixed>> */
    public function findAvailableInventory(string $organizationId, array $criteria = []): array;

    /** @return array<string,mixed>|null */
    public function getPropertyPresentation(string $organizationId, string|int $reference): ?array;
}
