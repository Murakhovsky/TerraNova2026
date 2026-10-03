<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$stage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringManagerStageExecutor.php');
$orchestrator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringOrchestrator.php');

foreach (['manager->prepare', 'agentRuns->start', 'manager->execute', 'agentRuns->complete', 'ArtifactType::FEATURE_SPEC'] as $needle) {
    if (!str_contains($stage, $needle)) throw new RuntimeException('Manager stage executor missing '.$needle);
}
if (!str_contains($orchestrator, 'managerStage->execute')) throw new RuntimeException('EngineeringOrchestrator does not delegate Manager stage.');
if (str_contains($orchestrator, 'ArtifactType::FEATURE_SPEC')) throw new RuntimeException('EngineeringOrchestrator still owns Manager artifact details.');

echo "Engineering Manager stage extraction passed.\n";
