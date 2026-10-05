<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$orchestrator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringOrchestrator.php');
$managerStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringManagerStageExecutor.php');
$productStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringProductRequirementsStageExecutor.php');

foreach (['productStage->execute','activeIdForFeature','persistTransitions','AgentRole::PRODUCT_REQUIREMENTS'] as $needle) {
    if (!str_contains($orchestrator, $needle)) throw new RuntimeException('V2 Product orchestrator contract missing '.$needle);
}
if (!str_contains($managerStage, 'EngineeringProductRequirementsStageExecutor')) {
    throw new RuntimeException('Deprecated Manager stage is not a Product compatibility adapter.');
}
foreach (['EngineeringProductRequirementsAnalysisService','ArtifactType::FEATURE_SPEC','createFromProductRequirements','AgentRole::PRODUCT_REQUIREMENTS'] as $needle) {
    if (!str_contains($productStage, $needle)) throw new RuntimeException('Product stage contract missing '.$needle);
}

echo "Engineering Product orchestrator slice passed.\n";
