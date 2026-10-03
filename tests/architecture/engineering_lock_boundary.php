<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$orchestrator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringOrchestrator.php');
$managerStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringManagerStageExecutor.php');
$architectStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringArchitectStageExecutor.php');
$developerStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringDeveloperStageExecutor.php');
$reviewerStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringReviewerStageExecutor.php');
$lock = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Lock/MySqlEngineeringWorkflowLock.php');

if (!str_contains($orchestrator, 'activeIdForFeature')) throw new RuntimeException('Duplicate active workflow guard missing.');

foreach ([$managerStage, $architectStage, $developerStage, $reviewerStage] as $stage) {
    if (!str_contains($stage, 'agents->run')) continue;
    $run = strpos($stage, 'agents->run');
    $lockEnd = strrpos(substr($stage, 0, $run), 'lock->synchronized');
    if ($lockEnd === false) throw new RuntimeException('Agent stage is missing its durable pre-run lock.');
}

if (!str_contains($managerStage, '// External LLM work deliberately runs outside the feature lock.')) {
    throw new RuntimeException('Manager external Agent call boundary marker is missing.');
}
if (!str_contains($lock, "'engineering:feature:'.\$featureId.':workflow'")) {
    throw new RuntimeException('Feature lock key contract missing.');
}

echo "Engineering feature lock boundary passed.\n";
