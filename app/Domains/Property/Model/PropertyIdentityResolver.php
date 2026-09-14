<?php
declare(strict_types=1);

namespace Domains\Property\Model;

final class PropertyIdentityResolver
{
    /**
     * @param list<PropertyIdentityResolutionCandidate> $candidates
     */
    public function resolve(
        string $organizationId,
        PropertyIdentitySignals $incoming,
        array $candidates,
    ): PropertyIdentityResolution {
        $bestIdentity = null;
        $bestScore = 0.0;
        $bestReasons = [];

        foreach ($candidates as $candidate) {
            if (!$candidate instanceof PropertyIdentityResolutionCandidate) {
                continue;
            }
            if ($candidate->identity->organizationId !== $organizationId) {
                continue;
            }

            [$score, $reasons] = $this->score($incoming, $candidate->signals);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIdentity = $candidate->identity;
                $bestReasons = $reasons;
            }
        }

        if ($bestIdentity === null || $bestScore < 0.60) {
            return new PropertyIdentityResolution(
                PropertyIdentityResolutionDecision::from(PropertyIdentityResolutionDecision::CREATE),
                $bestScore,
                $bestReasons,
            );
        }

        $decision = $bestScore >= 0.90
            ? PropertyIdentityResolutionDecision::MERGE
            : PropertyIdentityResolutionDecision::REVIEW;

        return new PropertyIdentityResolution(
            PropertyIdentityResolutionDecision::from($decision),
            $bestScore,
            $bestReasons,
            $bestIdentity,
        );
    }

    /** @return array{0: float, 1: list<string>} */
    private function score(PropertyIdentitySignals $incoming, PropertyIdentitySignals $candidate): array
    {
        $incomingRefs = $incoming->normalizedExternalReferenceKeys();
        $candidateRefs = $candidate->normalizedExternalReferenceKeys();
        if ($incomingRefs !== [] && array_intersect($incomingRefs, $candidateRefs) !== []) {
            return [1.0, ['external_reference']];
        }

        if ($this->sameNonBlank($incoming->cadastralNumber, $candidate->cadastralNumber)) {
            return [1.0, ['cadastral_number']];
        }

        $score = 0.0;
        $reasons = [];

        $sameUnitPath = $this->sameNonBlank($incoming->developmentAssetId, $candidate->developmentAssetId)
            && $this->sameNonBlank($incoming->buildingAssetId, $candidate->buildingAssetId)
            && $this->sameNonBlank($incoming->unitLabel, $candidate->unitLabel);
        if ($sameUnitPath) {
            $score += 0.90;
            $reasons[] = 'development_building_unit';
        }

        if ($this->sameNonBlank($incoming->addressCanonicalKey, $candidate->addressCanonicalKey)) {
            $score += 0.45;
            $reasons[] = 'address';
        }

        if ($this->areasMatch($incoming->totalArea, $candidate->totalArea)) {
            $score += 0.15;
            $reasons[] = 'area';
        }

        return [min(1.0, $score), $reasons];
    }

    private function sameNonBlank(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        $left = strtolower(trim($left));
        $right = strtolower(trim($right));
        return $left !== '' && $left === $right;
    }

    private function areasMatch(?float $left, ?float $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }
        $tolerance = max(0.5, max($left, $right) * 0.01);
        return abs($left - $right) <= $tolerance;
    }
}
