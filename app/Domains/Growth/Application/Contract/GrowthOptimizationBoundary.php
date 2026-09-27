<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthOptimizationBoundary
{
    /** @return array<string,mixed> */
    public function generateRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function acceptRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $recommendationId,string $reason,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function dismissRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $recommendationId,string $reason,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function materializeRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $recommendationId,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function optimizationBrief(string $organizationId):array;
}
