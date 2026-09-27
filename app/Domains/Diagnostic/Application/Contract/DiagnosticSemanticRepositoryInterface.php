<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\Contract;

use DateTimeImmutable;
use Domains\Diagnostic\Model\Assessment;
use Domains\Diagnostic\Model\DiagnosticState;
use Domains\Diagnostic\Model\FactRevision;
use Domains\Diagnostic\Model\Hypothesis;
use Domains\Diagnostic\Report\RecommendationStatus;

interface DiagnosticSemanticRepositoryInterface
{
    public function appendFactRevision(
        string $organizationId,
        string $sessionId,
        FactRevision $revision,
        string $valueType,
    ): void;

    /** @param list<string> $upstreamIds */
    public function appendAssessmentRevision(
        string $organizationId,
        string $sessionId,
        string $assessmentId,
        int $revision,
        Assessment $assessment,
        array $upstreamIds,
        DateTimeImmutable $recordedAt,
    ): void;

    /** @param list<string> $causalPath */
    public function appendHypothesisRevision(
        string $organizationId,
        string $sessionId,
        Hypothesis $hypothesis,
        int $revision,
        array $causalPath,
        ?string $policyId,
        DateTimeImmutable $recordedAt,
    ): void;

    public function appendRecommendationTransition(
        string $organizationId,
        string $sessionId,
        string $recommendationId,
        int $transitionNo,
        ?RecommendationStatus $from,
        RecommendationStatus $to,
        ?string $actorReference,
        ?string $rationale,
        DateTimeImmutable $recordedAt,
    ): void;

    public function saveStateSnapshot(string $organizationId, DiagnosticState $state): void;
}
