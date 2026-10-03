<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Workflow\WorkflowDirective;

final readonly class EngineeringHumanDecisionResult
{
    public function __construct(
        public string $featureId,
        public string $workflowId,
        public string $decisionId,
        public string $state,
        public WorkflowDirective $next,
    ) {}
}
