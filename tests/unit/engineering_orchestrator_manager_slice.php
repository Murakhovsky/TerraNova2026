<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$orchestrator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringOrchestrator.php');
$managerStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringManagerStageExecutor.php');
$productStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringProductRequirementsStageExecutor.php');

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
    'AgentRole::ENGINEERING_MANAGER',
] as $needle) {
    if (!str_contains($managerStage, $needle)) {
        throw new RuntimeException('Manager stage contract missing '.$needle);
    }
}

foreach ([
    'AgentRole::PRODUCT_REQUIREMENTS',
    'createFromManager',
    'applyManagerAnalysis',
] as $needle) {
    if (!str_contains($productStage, $needle)) {
        throw new RuntimeException('Product / Requirements stage contract missing '.$needle);
    }
}

echo "Engineering Manager/Product orchestrator slice passed.\n";
