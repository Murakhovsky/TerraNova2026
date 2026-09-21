<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthApplicationBoundary
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function detectSignal(string $organizationId, int $actorId, string $correlationId, string $idempotencyKey, array $input): array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function detectCandidate(string $organizationId, int $actorId, string $correlationId, string $idempotencyKey, array $input): array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function researchCandidate(string $organizationId, int $actorId, string $correlationId, string $candidateId, string $idempotencyKey, array $input): array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function scoreCandidate(string $organizationId, int $actorId, string $correlationId, string $candidateId, string $idempotencyKey, array $input): array;

    /** @return array<string,mixed> */
    public function qualifyCandidate(string $organizationId, int $actorId, string $correlationId, string $candidateId, string $reason, string $idempotencyKey): array;

    /** @return array<string,mixed> */
    public function monitorCandidate(string $organizationId, int $actorId, string $correlationId, string $candidateId, string $reason, string $idempotencyKey): array;

    /** @return array<string,mixed> */
    public function disqualifyCandidate(string $organizationId, int $actorId, string $correlationId, string $candidateId, string $reason, string $idempotencyKey): array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function prepareHandoff(string $organizationId, int $actorId, string $correlationId, string $candidateId, string $idempotencyKey, array $input): array;

    /** @return array<string,mixed>|null */
    public function viewSignal(string $organizationId, string $signalId): ?array;

    /** @return array<string,mixed>|null */
    public function viewCandidate(string $organizationId, string $candidateId): ?array;
}
