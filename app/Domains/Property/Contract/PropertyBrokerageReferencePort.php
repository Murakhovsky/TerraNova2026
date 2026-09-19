<?php
declare(strict_types=1);

namespace Domains\Property\Contract;

/**
 * Narrow Property read boundary for brokerage orchestration.
 *
 * Kept distinct from the broader Sales PropertyReferencePort declaration so
 * each cross-domain relationship has one canonical contract identity.
 */
interface PropertyBrokerageReferencePort
{
    /** @return array<string,mixed>|null */
    public function getPropertyPresentation(string $organizationId, string|int $reference): ?array;

    /** @return array<string,mixed>|null */
    public function getInventorySnapshot(string $organizationId, string $inventoryId): ?array;
}
