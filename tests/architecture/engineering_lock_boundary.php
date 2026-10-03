<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$orchestrator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringOrchestrator.php');
$managerStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringManagerStageExecutor.php');
$architectStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringArchitectStageExecutor.php');
$developerStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringDeveloperStageExecutor.php');
$reviewerStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringReviewerStageExecutor.php');
$qaStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringQaStageExecutor.php');
$lock = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Lock/MySqlEngineeringWorkflowLock.php');

if (!str_contains($orchestrator, 'activeIdForFeature')) throw new RuntimeException('Duplicate active workflow guard missing.');

$managerStart = strpos($managerStage, 'agentRuns->start');
$managerExecute = strpos($managerStage, 'manager->execute');
$managerComplete = strpos($managerStage, 'agentRuns->complete');
if ($managerStart === false || $managerExecute === false || $managerComplete === false || !($managerStart < $managerExecute && $managerExecute < $managerComplete)) {
    throw new RuntimeException('Manager durable AgentRun boundary ordering is invalid.');
}

foreach ([$architectStage, $developerStage, $reviewerStage, $qaStage] as $stage) {
    $start = strpos($stage, 'agentRuns->start');
    $run = strpos($stage, 'agents->run');
    $complete = strpos($stage, 'agentRuns->complete');
    if ($start === false || $run === false || $complete === false || !($start < $run && $run < $complete)) {
        throw new RuntimeException('Specialist durable AgentRun boundary ordering is invalid.');
    }
}

if (!str_contains($lock, "'engineering:feature:'.\$featureId.':workflow'")) {
    throw new RuntimeException('Feature lock key contract missing.');
}

echo "Engineering feature lock boundary passed.\n";
