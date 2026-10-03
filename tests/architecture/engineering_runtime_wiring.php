<?php
declare(strict_types=1);

$services = (string) file_get_contents(dirname(__DIR__, 2).'/symfony/config/services.yaml');

$required = [
    'Infrastructure\\AI\\StructuredLlmAgentProvider:',
    'Kernel\\Agent\\Contract\\LlmProviderInterface:',
    'Kernel\\Agent\\Service\\AgentRuntimeEngine:',
    'Kernel\\Agent\\Contract\\AgentRuntimeInterface:',
    'App\\Engineering\\Application\\Agent\\EngineeringAgentRunnerInterface:',
    'App\\Engineering\\Application\\Context\\RepositoryDiscoveryInterface:',
    "\$repositoryRoot: '%kernel.project_dir%/..'",
];

foreach ($required as $needle) {
    if (!str_contains($services, $needle)) {
        throw new RuntimeException('Engineering runtime service wiring missing: '.$needle);
    }
}

echo "Engineering runtime wiring passed.\n";
