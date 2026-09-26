<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthResearchBoundary
{
    /** @return array<string,mixed> */
    public function generateProposal(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $idempotencyKey
    ): array;

    /** @return array<string,mixed> */
    public function acceptProposal(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $proposalId,string $idempotencyKey
    ): array;

    /** @return array<string,mixed> */
    public function researchBrief(string $organizationId,string $candidateId): array;
}
