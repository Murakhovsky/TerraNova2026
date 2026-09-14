<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Model;

final readonly class QuestionDefinition
{
    /** @param list<string> $targetFacts @param list<string> $targetCriteria */
    public function __construct(
        public string $id,
        public string $text,
        public array $targetFacts,
        public array $targetCriteria,
        public float $priority,
        public string $expectedAnswerType,
        public array $followUpConditions = [],
        public ?string $evidenceRequirementId = null,
        public float $cost = 1.0,
        public string $areaId = '',
        public array $allowedDiagnosticModes = [],
        public bool $enabled = true,
    ) {
    }
}
