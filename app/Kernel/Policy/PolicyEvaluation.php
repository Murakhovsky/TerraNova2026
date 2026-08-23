<?php
declare(strict_types=1);

namespace Kernel\Policy;

final readonly class PolicyEvaluation
{
    public function __construct(
        public PolicyDecision $decision,
        public ?ActionPolicy $policy,
        public string $reason,
    ) {}
}
