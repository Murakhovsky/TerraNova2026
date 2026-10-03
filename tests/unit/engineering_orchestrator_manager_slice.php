<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$orchestrator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringOrchestrator.php');

foreach ([
    'EngineeringManagerAnalysisService',
    'ArtifactType::FEATURE_SPEC',
    'ArtifactType::CONTEXT_MAP',
    'createFromManager',
    'AgentRole::ENGINEERING_MANAGER',
    'persistTransitions',
] as $needle) {
    if (!str_contains($orchestrator, $needle)) {
        throw new RuntimeException('Manager vertical slice contract missing '.$needle);
    }
}

echo "Engineering Manager orchestrator slice passed.\n";
