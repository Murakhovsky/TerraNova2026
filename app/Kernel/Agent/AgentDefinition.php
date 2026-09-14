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
        public array $evidenceSchemas = [],
        /** @var class-string<\Kernel\Agent\Contract\AgentResultValidatorInterface>|null */
        public ?string $resultValidatorClass = null,
        public string $domainName = 'kernel',
        public bool $enabled = true,
        public string $profile = 'default',
        public ?string $model = null,
        /** @var list<string>|null */
        public ?array $contextSources = null,
        public float $confidenceThreshold = 0.0,
        public int $maxActionsPerRun = 10,
        public bool $configurationManaged = true,
    ) {}
}
