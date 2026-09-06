<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Model;

final readonly class EvidenceRequirementDefinition
{
    /** @param list<string> $criterionIds @param list<string> $acceptedSourceTypes @param list<string> $hierarchy */
    public function __construct(
        public string $id,
        public array $criterionIds,
        public array $acceptedSourceTypes,
        public array $hierarchy,
        public int $minimumSources = 1,
        public float $minimumReliability = 0.0,
        public float $minimumDirectness = 0.0,
        public bool $required = true,
    ) {
    }
}
