<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthSignalCollectorBoundary
{
    /** @return array<string,mixed> */
    public function runCollector(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $collectorName,
        string $idempotencyKey,
        ?string $cursor = null,
        int $limit = 100,
    ): array;

    /** @return list<string> */
    public function collectors(): array;
}
