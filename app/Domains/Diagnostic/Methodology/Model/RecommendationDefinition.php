<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Model;

final readonly class RecommendationDefinition
{
    /** @param list<string> $triggerRules @param list<string> $criterionIds @param list<string> $actions @param list<string> $successMetrics @param list<string> $dependencies */
    public function __construct(
        public string $id,
        public array $triggerRules,
        public array $criterionIds,
        public string $targetProblem,
        public string $title,
        public string $rationale,
        public array $actions,
        public string $expectedImpact,
        public string $implementationEffort,
        public string $priority,
        public array $successMetrics,
        public array $dependencies = [],
        public string $ownerRole = 'Sales Manager',
        public string $description = '',
        public array $targetFindings = [],
        public string $costLevel = 'medium',
        public ?string $implementationTime = null,
    ) {
    }
}
