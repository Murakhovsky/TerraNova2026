<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Model;
use DateTimeImmutable;
final readonly class DiagnosticState
{
    public function __construct(
        public string $diagnosticId, public string $packId, public int $packVersion, public int $revision, public DateTimeImmutable $computedAt,
        public array $knownFacts, public array $missingFacts, public array $contradictions, public array $evidence,
        public array $assessments, public array $findings, public array $hypotheses, public array $rootCauses, public array $recommendations,
        public array $unresolvedQuestions, public array $coverage, public float $confidence, public array $scores,
        public array $blockedNodes, public array $applicableNodes, public array $inputIds,
    ) {}
}
