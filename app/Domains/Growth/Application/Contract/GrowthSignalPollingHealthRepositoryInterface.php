<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use DateTimeImmutable;

interface GrowthSignalPollingHealthRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function state(string $organizationId,string $collectorName): ?array;

    /** @return list<array<string,mixed>> */
    public function statesForOrganization(string $organizationId): array;

    public function markHealthy(string $organizationId,string $collectorName,DateTimeImmutable $at): void;

    public function markDegraded(
        string $organizationId,
        string $collectorName,
        DateTimeImmutable $at,
        ?string $errorSummary,
    ): void;

    public function markFailed(
        string $organizationId,
        string $collectorName,
        DateTimeImmutable $at,
        DateTimeImmutable $nextRetryAt,
        string $errorSummary,
    ): void;
}
