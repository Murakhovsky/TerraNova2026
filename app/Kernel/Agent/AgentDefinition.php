<?php
declare(strict_types=1);

namespace Kernel\Agent;

final readonly class AgentDefinition
{
    public function __construct(
        public string $name,
        public string $version,
        public string $systemPrompt,
        public string $promptVersion,
        public string $schemaVersion,
        /** @var list<string> */
        public array $allowedActionTypes,
        public string $defaultExecutionMode = 'APPROVAL_REQUIRED',
        public string $defaultRiskLevel = 'MEDIUM',
    ) {}
}
