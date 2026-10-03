<?php
declare(strict_types=1);

namespace App\Engineering\Application\Workflow;

use App\Engineering\Domain\Agent\AgentRole;

final readonly class WorkflowDirective
{
    public function __construct(
        public WorkflowDirectiveType $type,
        public ?AgentRole $agent = null,
        public string $reason = '',
    ) {
    }
}
