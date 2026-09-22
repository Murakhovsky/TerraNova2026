<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthEngagementBoundary
{
    /** @return array<string,mixed> */
    public function generateRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function acceptRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $reason,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function dismissRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $reason,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function engagementBrief(string $organizationId,string $candidateId):array;
}
