<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthEngagementExecutionRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function byRecommendation(string $organizationId,string $recommendationId):?array;

    /** @return array<string,mixed>|null */
    public function byActionId(string $organizationId,string $actionId):?array;

    public function createOrVerify(
        string $organizationId,
        string $executionId,
        string $candidateId,
        string $recommendationId,
        string $targetDomain,
        string $targetReferenceType,
        string $targetReferenceId,
        string $actionId,
        string $actionType,
        string $channel,
        string $payloadFingerprint,
        int $actorId,
    ):void;

    /** @return array<string,mixed>|null */
    public function latestForCandidate(string $organizationId,string $candidateId):?array;

    public function countPreHandoffSince(string $organizationId,string $since):int;

    /** @return array<string,mixed>|null */
    public function latestPreHandoffForTarget(string $organizationId,string $targetReferenceId):?array;
}
