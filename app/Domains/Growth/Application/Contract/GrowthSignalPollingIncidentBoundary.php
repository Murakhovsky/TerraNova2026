<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use DateTimeImmutable;

interface GrowthSignalPollingIncidentBoundary
{
    /** @return array<string,mixed>|null */
    public function recordFailure(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $collectorName,
        int $consecutiveFailures,
        string $errorSummary,
        DateTimeImmutable $failedAt,
        DateTimeImmutable $nextRetryAt,
    ): ?array;

    /** @return array<string,mixed>|null */
    public function recordRecovery(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $collectorName,
        DateTimeImmutable $recoveredAt,
    ): ?array;

    /** @return list<array<string,mixed>> */
    public function activeIncidents(string $organizationId): array;
}
