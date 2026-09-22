<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthSignalIntakeRepositoryInterface
{
    public function createRun(
        string $organizationId,
        string $runId,
        string $collectorName,
        ?string $requestCursor,
        int $requestedLimit,
        int $actorId,
    ): void;

    /** @return array<string,mixed>|null */
    public function viewRun(string $organizationId,string $runId): ?array;

    public function completeRun(
        string $organizationId,
        string $runId,
        string $status,
        int $collectedCount,
        int $acceptedCount,
        int $duplicateCount,
        int $failedCount,
        ?string $nextCursor,
        ?string $errorSummary,
    ): void;

    public function failRun(string $organizationId,string $runId,string $errorSummary): void;

    public function claimSource(
        string $organizationId,
        string $collectorName,
        string $externalKey,
        string $payloadFingerprint,
        string $signalId,
    ): bool;
}
