<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Result;

use Domains\Diagnostic\Methodology\Model\DependencyDefinition;

final readonly class DiagnosticResult
{
    /**
     * @param array<string,CriterionAssessment> $assessments
     * @param array<string,float|null> $sectionScores
     * @param list<Finding> $findings
     * @param list<DependencyDefinition> $dependencies
     */
    public function __construct(
        public string $packId,
        public int $packVersion,
        public array $assessments,
        public array $sectionScores,
        public ?float $score,
        public Coverage $coverage,
        public float $confidence,
        public array $findings,
        public array $dependencies,
    ) {
    }
}
