<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Result;

final readonly class CriterionAssessment
{
    /** @param list<Finding> $findings @param list<string> $evidenceIds */
    public function __construct(
        public string $criterionId,
        public ?float $score,
        public Coverage $coverage,
        public float $confidence,
        public array $findings,
        public array $evidenceIds = [],
    ) {
    }
}
