<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthAutonomousContentRepositoryInterface
{
    /** @param array<string,mixed> $context */
    public function createRun(
        string $organizationId,string $runId,string $candidateId,string $recommendationId,array $context,
        string $promptVersion,string $schemaVersion,int $actorId
    ):void;

    public function completeRun(
        string $organizationId,string $runId,string $draftId,string $provider,string $model,
        ?int $inputTokens,?int $outputTokens,?float $costAmount,?string $costCurrency
    ):void;

    public function failRun(string $organizationId,string $runId,string $errorSummary):void;

    /** @return array<string,mixed>|null */
    public function viewRun(string $organizationId,string $runId):?array;

    /** @return array<string,mixed>|null */
    public function latestReviewProfile(string $organizationId):?array;

    /** @param array<string,mixed> $profile */
    public function appendReviewProfile(array $profile):void;

    /** @param array<string,mixed> $draft */
    public function appendDraft(array $draft):void;

    /** @return array<string,mixed>|null */
    public function latestDraft(string $organizationId,string $recommendationId):?array;

    /** @return array<string,mixed>|null */
    public function viewDraft(string $organizationId,string $draftId):?array;

    /** @return array<string,mixed> */
    public function lockDraft(string $organizationId,string $draftId):array;

    public function decideDraft(string $organizationId,string $draftId,string $status,string $reason,int $actorId,string $reviewCode):void;

    /** @return list<array<string,mixed>> */
    public function draftCandidates(string $organizationId,int $limit):array;
}
