<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$store = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringAgentRunStore.php');
$orchestrator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringOrchestrator.php');

foreach (['existsByIdempotencyKey', 'inputSnapshot', 'idempotencyKey', 'recordCompleted'] as $needle) {
    if (!str_contains($store.$orchestrator, $needle)) throw new RuntimeException('Engineering AgentRun persistence missing '.$needle);
}
if (strpos($orchestrator, 'recordCompleted') > strpos($orchestrator, 'ArtifactType::FEATURE_SPEC')) {
    throw new RuntimeException('AgentRun must be persisted before artifacts reference it.');
}

echo "Engineering AgentRun persistence passed.\n";
