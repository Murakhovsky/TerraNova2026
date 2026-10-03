<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$orchestrator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringOrchestrator.php');
$decision = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringHumanDecisionService.php');

foreach ([$orchestrator, $decision] as $content) {
    if (!str_contains($content, 'progression->continue')) {
        throw new RuntimeException('Autonomous progression is not connected to all resume/start entry points.');
    }
}

echo "Engineering autonomous progression wiring passed.\n";
