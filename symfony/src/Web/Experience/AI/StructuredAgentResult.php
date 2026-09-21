<?php

declare(strict_types=1);

namespace App\Web\Experience\AI;

use App\Web\Experience\Model\EntityRef;

final readonly class StructuredAgentResult
{
    /**
     * @param array<string,mixed> $summary
     * @param list<AgentRecommendation> $recommendations
     * @param list<AgentWarning> $warnings
     * @param list<AgentMetric> $metrics
     * @param list<EntityRef> $entities
     * @param list<AgentEvidence> $evidence
     */
    public function __construct(
        public array $summary,
        public array $recommendations,
        public array $warnings,
        public array $metrics,
        public array $entities,
        public array $evidence,
    ) {
    }
}
