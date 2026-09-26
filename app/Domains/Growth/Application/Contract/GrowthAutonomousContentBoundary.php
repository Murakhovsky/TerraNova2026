<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthAutonomousContentBoundary
{
    /** @return array<string,mixed> */
    public function viewReviewPolicy(string $organizationId):array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function updateReviewPolicy(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input):array;

    /** @return array<string,mixed> */
    public function generateDraft(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $idempotencyKey,string $actorType='USER'
    ):array;

    /** @return array<string,mixed> */
    public function contentBrief(string $organizationId,string $candidateId,string $recommendationId):array;

    /** @return array<string,mixed> */
    public function approveDraft(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $draftId,string $reason,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function rejectDraft(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $draftId,string $reason,string $idempotencyKey
    ):array;
}
