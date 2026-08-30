<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Model;

final readonly class CriterionDefinition
{
    /**
     * @param list<string> $required
     * @param list<string> $optional
     * @param array<string,float> $requiredWeights
     * @param array<string,float> $optionalWeights
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $sectionId,
        public array $required,
        public array $optional = [],
        public float $weight = 1.0,
        public float $minimumCoverage = 1.0,
        public float $minimumConfidence = 0.0,
        public array $requiredWeights = [],
        public array $optionalWeights = [],
    ) {
    }

    public function requiredWeight(string $reference): float
    {
        return $this->requiredWeights[$reference] ?? 1.0;
    }
}
