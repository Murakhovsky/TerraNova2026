<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyIdentityWorkflowRepositoryInterface
{
    public function context(string $organizationId, int $submissionId, int $legacyPropertyId): ?array;
    public function candidates(string $organizationId, string $typeCode, int $limit = 200): array;
    public function canonicalForLegacy(string $organizationId, int $legacyPropertyId): ?string;
    public function recordResolution(string $organizationId, int $submissionId, ?string $candidateAssetId, ?string $resolvedAssetId, string $decision, float $score, array $signals, array $reasons, string $reviewStatus): int;
    public function materialize(string $organizationId, int $submissionId, int $legacyPropertyId, array $context, ?string $targetAssetId = null): string;
    public function pendingReviews(string $organizationId, int $limit = 100): array;
    public function resolution(string $organizationId, int $resolutionId): ?array;
    public function completeReview(string $organizationId, int $resolutionId, string $decision, string $assetId, ?string $reviewerReference, ?string $note): void;
}
