<?php
declare(strict_types=1);

namespace Kernel\Agent;

final readonly class AgentInvocation
{
    public function __construct(
        public string $organizationId,
        public string $subjectType,
        public string $subjectId,
        public string $question,
        public string $correlationId,
        public array $contextReferences = [],
    ) {}
}
