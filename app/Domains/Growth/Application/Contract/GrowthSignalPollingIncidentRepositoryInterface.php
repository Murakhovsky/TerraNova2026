<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use DateTimeImmutable;

interface GrowthSignalPollingIncidentRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function openIncident(string $organizationId,string $collectorName): ?array;

    /** @return list<array<string,mixed>> */
    public function activeIncidents(string $organizationId): array;

    /** @return array<string,mixed> */
    public function upsertOpen(
        string $organizationId,
        string $incidentId,
        string $collectorName,
        int $failureCount,
        string $errorSummary,
        DateTimeImmutable $openedAt,
        DateTimeImmutable $lastFailureAt,
        DateTimeImmutable $nextRetryAt,
    ): array;

    /** @return array<string,mixed>|null */
    public function resolveOpen(string $organizationId,string $collectorName,DateTimeImmutable $resolvedAt): ?array;
}
