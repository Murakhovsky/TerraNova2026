<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$provider = (string) file_get_contents($root.'/app/Infrastructure/AI/StructuredLlmAgentProvider.php');
$factory = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Agent/EngineeringAgentDefinitionFactory.php');
$compose = (string) file_get_contents($root.'/docker-compose.yml');
$envExample = (string) file_get_contents($root.'/.env.docker.example');

if (!str_contains($provider, 'useCase: $this->useCase($definition)')) throw new RuntimeException('Canonical AgentRuntime provider still uses a fixed use case.');
foreach (['managerModel','architectModel','developerModel','reviewerModel','qaModel','model($role)','promptFile($role)','config/engineering/prompts/'] as $needle) {
    if (!str_contains($factory, $needle)) throw new RuntimeException('Engineering model routing hint missing '.$needle);
}
foreach (['COS_ENGINEERING_MANAGER_MODEL','COS_ENGINEERING_ARCHITECT_MODEL','COS_ENGINEERING_DEVELOPER_MODEL','COS_ENGINEERING_REVIEWER_MODEL','COS_ENGINEERING_QA_MODEL'] as $env) {
    if (!str_contains($compose, $env)) throw new RuntimeException('Docker runtime does not pass engineering model route '.$env);
    if (!str_contains($envExample, $env)) throw new RuntimeException('Docker env example does not document engineering model route '.$env);
}

echo "Engineering model routing passed.\n";
