<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\OpportunityHandoff;

interface GrowthHandoffRepositoryInterface
{
    public function createAttempt(
        string $organizationId,
        string $attemptId,
        OpportunityHandoff $handoff,
        string $payloadFingerprint,
        int $actorId,
    ): void;

    /** @return array<string,mixed>|null */
    public function viewAttempt(string $organizationId,string $attemptId): ?array;

    public function hasRunningAttempt(string $organizationId,string $candidateId): bool;

    public function acceptAttempt(
        string $organizationId,string $attemptId,string $referenceType,string $referenceId,string $reason
    ): void;

    public function rejectAttempt(string $organizationId,string $attemptId,string $reason): void;

    public function failAttempt(string $organizationId,string $attemptId,string $errorSummary): void;

    /** @return array<string,mixed>|null */
    public function latestAttempt(string $organizationId,string $candidateId): ?array;

    public function candidateByTargetReference(
        string $organizationId,string $targetDomain,string $referenceType,string $referenceId
    ): ?string;
}
