<?php

declare(strict_types=1);

namespace App\Web\Experience\AI;

use Kernel\Agent\AgentRunProjection;

final readonly class AgentRunViewModel
{
    /** @param list<AgentActionView> $actions */
    public function __construct(
        public AgentRunProjection $run,
        public StructuredAgentResult $result,
        public array $actions,
    ) {
    }
}
