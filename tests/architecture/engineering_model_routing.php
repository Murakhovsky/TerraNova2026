<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$provider = (string) file_get_contents($root.'/app/Infrastructure/AI/StructuredLlmAgentProvider.php');
$factory = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Agent/EngineeringAgentDefinitionFactory.php');

if (!str_contains($provider, 'useCase: $this->useCase($definition)')) throw new RuntimeException('Canonical AgentRuntime provider still uses a fixed use case.');
foreach (['managerModel','architectModel','developerModel','reviewerModel','qaModel','model($role)'] as $needle) {
    if (!str_contains($factory, $needle)) throw new RuntimeException('Engineering model routing hint missing '.$needle);
}

echo "Engineering model routing passed.\n";
