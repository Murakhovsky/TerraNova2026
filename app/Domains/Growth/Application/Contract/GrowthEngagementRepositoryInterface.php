<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Domain\EngagementRecommendation;

interface GrowthEngagementRepositoryInterface
{
    /** @param array<string,mixed> $contextSnapshot */
    public function createRun(
        string $organizationId,string $runId,string $candidateId,array $contextSnapshot,
        string $promptVersion,string $schemaVersion,int $actorId
    ):void;

    public function completeRun(
        string $organizationId,string $runId,string $recommendationId,string $provider,string $model,
        ?int $inputTokens,?int $outputTokens,?float $costAmount,?string $costCurrency
    ):void;

    public function failRun(string $organizationId,string $runId,string $errorSummary):void;

    /** @return array<string,mixed>|null */
    public function viewRun(string $organizationId,string $runId):?array;

    /** @return list<string> */
    public function supersedeProposedForCandidate(
        string $organizationId,string $candidateId,string $reason,int $actorId
    ):array;

    public function createRecommendation(EngagementRecommendation $recommendation,string $runId,int $actorId):void;

    public function lockRecommendation(string $organizationId,string $recommendationId):EngagementRecommendation;

    public function updateRecommendation(EngagementRecommendation $recommendation,int $actorId):void;

    /** @return array<string,mixed>|null */
    public function viewRecommendation(string $organizationId,string $recommendationId):?array;

    /** @return array<string,mixed>|null */
    public function latestRecommendation(string $organizationId,string $candidateId):?array;
}
