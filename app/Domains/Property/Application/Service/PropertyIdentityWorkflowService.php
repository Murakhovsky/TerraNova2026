<?php
declare(strict_types=1);

namespace Domains\Property\Application\Service;

use Domains\Property\Application\Contract\PropertyIdentityWorkflowRepositoryInterface;
use Domains\Property\Model\PropertyIdentity;
use Domains\Property\Model\PropertyIdentityResolutionCandidate;
use Domains\Property\Model\PropertyIdentityResolutionDecision;
use Domains\Property\Model\PropertyIdentityResolver;
use Domains\Property\Model\PropertyIdentitySignals;
use InvalidArgumentException;

final readonly class PropertyIdentityWorkflowService
{
    public function __construct(
        private PropertyIdentityWorkflowRepositoryInterface $repository,
        private PropertyIdentityResolver $resolver = new PropertyIdentityResolver(),
    ) {}

    public function resolvePublished(string $organizationId, int $submissionId, int $legacyPropertyId): array
    {
        if ($submissionId <= 0 || $legacyPropertyId <= 0) {
            throw new InvalidArgumentException('Published Property identity resolution requires submission and legacy property ids.');
        }
        $existing = $this->repository->canonicalForLegacy($organizationId, $legacyPropertyId);
        if ($existing !== null) {
            return ['decision' => 'linked', 'status' => 'resolved', 'asset_id' => $existing, 'score' => 1.0, 'reasons' => ['legacy_property_link']];
        }
        $context = $this->repository->context($organizationId, $submissionId, $legacyPropertyId);
        if ($context === null) throw new InvalidArgumentException('Published Property identity context was not found.');
        $incoming = $this->signals($context);
        $candidates = [];
        foreach ($this->repository->candidates($organizationId, (string) ($context['type_code'] ?? ''), 200) as $candidate) {
            $assetId = trim((string) ($candidate['asset_id'] ?? ''));
            if ($assetId === '') continue;
            $candidates[] = new PropertyIdentityResolutionCandidate(new PropertyIdentity($organizationId, $assetId), $this->signals($candidate));
        }
        $resolution = $this->resolver->resolve($organizationId, $incoming, $candidates);
        $candidateAssetId = $resolution->candidateIdentity?->assetId;
        $decision = $resolution->decision->value;
        $signals = $this->signalSnapshot($incoming);
        if ($decision === PropertyIdentityResolutionDecision::REVIEW) {
            $resolutionId = $this->repository->recordResolution($organizationId, $submissionId, $candidateAssetId, null, $decision, $resolution->score, $signals, $resolution->reasons, 'pending');
            return ['resolution_id' => $resolutionId, 'decision' => $decision, 'status' => 'pending_review', 'candidate_asset_id' => $candidateAssetId, 'score' => $resolution->score, 'reasons' => $resolution->reasons];
        }
        $target = $decision === PropertyIdentityResolutionDecision::MERGE ? $candidateAssetId : null;
        $assetId = $this->repository->materialize($organizationId, $submissionId, $legacyPropertyId, $context, $target);
        $resolutionId = $this->repository->recordResolution($organizationId, $submissionId, $candidateAssetId, $assetId, $decision, $resolution->score, $signals, $resolution->reasons, 'resolved');
        return ['resolution_id' => $resolutionId, 'decision' => $decision, 'status' => 'resolved', 'asset_id' => $assetId, 'score' => $resolution->score, 'reasons' => $resolution->reasons];
    }

    public function pendingReviews(string $organizationId, int $limit = 100): array
    {
        return $this->repository->pendingReviews($organizationId, $limit);
    }

    public function review(string $organizationId, int $resolutionId, string $action, ?string $targetAssetId = null, ?string $reviewerReference = null, ?string $note = null): array
    {
        $action = strtolower(trim($action));
        if (!in_array($action, [PropertyIdentityResolutionDecision::MERGE, PropertyIdentityResolutionDecision::CREATE], true)) {
            throw new InvalidArgumentException('Identity review action must be merge or create.');
        }
        $row = $this->repository->resolution($organizationId, $resolutionId);
        if ($row === null || (string) ($row['review_status'] ?? '') !== 'pending') throw new InvalidArgumentException('Pending Property identity review was not found.');
        $submissionId = (int) ($row['submission_id'] ?? 0);
        $legacyPropertyId = (int) ($row['legacy_property_id'] ?? 0);
        $context = $this->repository->context($organizationId, $submissionId, $legacyPropertyId);
        if ($context === null) throw new InvalidArgumentException('Identity review context was not found.');
        if ($action === PropertyIdentityResolutionDecision::MERGE) {
            $targetAssetId = trim((string) ($targetAssetId ?: ($row['candidate_asset_id'] ?? '')));
            if ($targetAssetId === '') throw new InvalidArgumentException('Identity merge review requires a target canonical asset.');
        } else {
            $targetAssetId = null;
        }
        $assetId = $this->repository->materialize($organizationId, $submissionId, $legacyPropertyId, $context, $targetAssetId);
        $this->repository->completeReview($organizationId, $resolutionId, $action, $assetId, $reviewerReference, $note);
        return ['resolution_id' => $resolutionId, 'decision' => $action, 'status' => 'resolved', 'asset_id' => $assetId];
    }

    private function signals(array $row): PropertyIdentitySignals
    {
        $refs = $row['external_reference_keys'] ?? [];
        if (is_string($refs)) $refs = array_values(array_filter(array_map('trim', explode(',', $refs))));
        if (!is_array($refs)) $refs = [];
        return new PropertyIdentitySignals(
            externalReferenceKeys: array_values(array_filter(array_map('strval', $refs))),
            cadastralNumber: $this->nullableString($row['cadastral_number'] ?? null),
            addressCanonicalKey: $this->nullableString($row['address_canonical_key'] ?? null),
            developmentAssetId: $this->nullableString($row['development_asset_id'] ?? null),
            buildingAssetId: $this->nullableString($row['building_asset_id'] ?? null),
            unitLabel: $this->nullableString($row['unit_label'] ?? null),
            totalArea: is_numeric($row['total_area'] ?? null) ? (float) $row['total_area'] : null,
        );
    }

    private function signalSnapshot(PropertyIdentitySignals $signals): array
    {
        return [
            'external_reference_keys' => $signals->normalizedExternalReferenceKeys(),
            'cadastral_number' => $signals->cadastralNumber,
            'address_canonical_key' => $signals->addressCanonicalKey,
            'development_asset_id' => $signals->developmentAssetId,
            'building_asset_id' => $signals->buildingAssetId,
            'unit_label' => $signals->unitLabel,
            'total_area' => $signals->totalArea,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
