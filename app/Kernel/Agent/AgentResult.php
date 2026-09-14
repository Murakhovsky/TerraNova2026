<?php
declare(strict_types=1);

namespace Kernel\Agent;

final readonly class AgentResult
{
    public function __construct(
        public string $decision,
        public string $reason,
        public float $confidence,
        public array $proposedActions,
        public array $evidence = [],
    ) {
    }
}
