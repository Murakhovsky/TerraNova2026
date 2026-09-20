<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyProjectionInterface
{
    public function sync(string $organizationId, string $assetId): ?int;

    /** @param array<string,mixed> $metadata */
    public function syncOperationalMetadata(string $organizationId, int $legacyPropertyId, array $metadata): void;

    public function recordActivity(
        string $organizationId,
        int $legacyPropertyId,
        ?int $userId,
        string $activityType,
        string $title,
        ?string $body = null,
        ?string $oldValue = null,
        ?string $newValue = null,
    ): void;
}
