<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthExperimentDecisionBoundary
{
    /** @return array<string,mixed> */
    public function generateRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function acceptRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,
        string $recommendationId,string $reason,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function dismissRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,
        string $recommendationId,string $reason,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function decisionBrief(string $organizationId,string $experimentId):array;
}
