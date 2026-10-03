<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Workflow;

final readonly class WorkflowTransitionContext
{
    public function __construct(
        public string $trigger,
        public string $reason,
        public string $initiatedByType,
        public string $initiatedById,
        public ?string $agentRunId = null,
        public ?string $humanDecisionId = null,
        public array $metadata = [],
    ) {
    }
}
