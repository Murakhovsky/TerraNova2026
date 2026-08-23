<?php
declare(strict_types=1);

namespace Kernel\Policy;

final readonly class ActionPolicy
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $actionType,
        public array $conditions,
        public PolicyDecision $decision,
        public int $priority = 100,
    ) {
    }
}
