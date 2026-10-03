<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$store = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringAgentRunStore.php');
$orchestrator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringOrchestrator.php');

foreach (['existsByIdempotencyKey', 'inputSnapshot', 'idempotencyKey', 'start(', 'complete('] as $needle) {
    if (!str_contains($store.$orchestrator, $needle)) {
        throw new RuntimeException('Engineering AgentRun persistence missing '.$needle);
    }
}
if (strpos($orchestrator, 'agentRuns->start') > strpos($orchestrator, 'manager->execute')) {
    throw new RuntimeException('Engineering AgentRun intent must be persisted before LLM execution.');
}
if (strpos($orchestrator, 'agentRuns->complete') > strpos($orchestrator, 'ArtifactType::FEATURE_SPEC')) {
    throw new RuntimeException('Engineering AgentRun must complete before artifacts reference it.');
}

echo "Engineering AgentRun persistence passed.\n";
