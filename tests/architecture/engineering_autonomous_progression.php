<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$orchestrator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringOrchestrator.php');
$decision = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringHumanDecisionService.php');
$progression = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringAutonomousProgressionService.php');

foreach ([$orchestrator, $decision] as $content) {
    if (!str_contains($content, 'progression->continue')) {
        throw new RuntimeException('Autonomous progression is not connected to all resume/start entry points.');
    }
}

foreach (['maxStepsPerProgression','maxLogicalAgentRunsPerFeature','authorizedRunBudget','AUTONOMY_BUDGET','requireHumanDecision','openForFeature'] as $needle) {
    if (!str_contains($progression, $needle)) {
        throw new RuntimeException('Autonomous progression persistent safety gate missing '.$needle);
    }
}

echo "Engineering autonomous progression wiring passed.\n";
