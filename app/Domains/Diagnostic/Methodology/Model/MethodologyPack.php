<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Model;

final readonly class MethodologyPack
{
    /**
     * @param list<SectionDefinition> $sections
     * @param list<CriterionDefinition> $criteria
     * @param list<MetricDefinition> $metrics
     * @param list<RuleDefinition> $rules
     * @param list<ScoringDefinition> $scoring
     * @param list<DependencyDefinition> $dependencies
     * @param list<FactDefinition> $facts
     */
    public function __construct(
        public string $id,
        public int $version,
        public string $name,
        public array $sections,
        public array $criteria,
        public array $metrics,
        public array $rules = [],
        public array $scoring = [],
        public array $dependencies = [],
        public array $facts = [],
    ) {
    }
}
