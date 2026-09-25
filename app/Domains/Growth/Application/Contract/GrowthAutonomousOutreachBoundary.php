<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthAutonomousOutreachBoundary
{
    /** @return array<string,mixed> */
    public function viewPolicy(string $organizationId):array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function updatePolicy(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input):array;

    /** @return array<string,mixed> */
    public function stagePayload(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $body,string $reason,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function recommendationBrief(string $organizationId,string $candidateId,string $recommendationId):array;

    /** @return array<string,mixed> */
    public function triggerRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,string $triggerKey
    ):array;
}
