<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Workflow\WorkflowDirective;

final readonly class EngineeringStartResult
{
    public function __construct(
        public string $featureId,
        public string $workflowId,
        public string $state,
        public WorkflowDirective $next,
    ) {
    }
}
