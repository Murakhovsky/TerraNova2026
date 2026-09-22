<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthDecisionBoundary
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createQualificationPolicy(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input): array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function reviseQualificationPolicy(string $organizationId,int $actorId,string $correlationId,string $policyId,int $baseRevision,string $idempotencyKey,array $input): array;

    /** @return array<string,mixed> */
    public function activateQualificationPolicy(string $organizationId,int $actorId,string $correlationId,string $policyId,int $revision,string $idempotencyKey): array;

    /** @return array<string,mixed> */
    public function evaluateCandidate(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $policyId,int $revision,string $idempotencyKey): array;

    /** @return array<string,mixed> */
    public function decisionBrief(string $organizationId,string $candidateId): array;
}
