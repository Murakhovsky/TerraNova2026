<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Domain\Agent\AgentRole;

final readonly class EngineeringAgentRunResult
{
    public function __construct(
        public string $runId,
        public AgentRole $role,
        public string $status,
        public array $structuredOutput,
        public ?string $provider,
        public ?string $model,
        public array $usage,
        public ?string $error = null,
    ) {
    }
}
