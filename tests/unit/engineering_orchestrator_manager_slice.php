<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$orchestrator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringOrchestrator.php');
$managerStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringManagerStageExecutor.php');

foreach ([
    'managerStage->execute',
    'activeIdForFeature',
    'persistTransitions',
] as $needle) {
    if (!str_contains($orchestrator, $needle)) {
        throw new RuntimeException('Manager orchestrator contract missing '.$needle);
    }
}

foreach ([
    'EngineeringManagerAnalysisService',
    'ArtifactType::FEATURE_SPEC',
    'ArtifactType::CONTEXT_MAP',
    'createFromManager',
    'AgentRole::ENGINEERING_MANAGER',
] as $needle) {
    if (!str_contains($managerStage, $needle)) {
        throw new RuntimeException('Manager stage contract missing '.$needle);
    }
}

echo "Engineering Manager orchestrator slice passed.\n";
