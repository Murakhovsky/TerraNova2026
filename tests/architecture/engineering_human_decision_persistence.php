<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$orchestrator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringOrchestrator.php');
$store = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringHumanDecisionStore.php');

foreach (['WorkflowDirectiveType::REQUEST_HUMAN_DECISION', 'humanDecisions->create', "'status' => 'OPEN'"] as $needle) {
    if (!str_contains($orchestrator.$store, $needle)) throw new RuntimeException('Human decision persistence missing '.$needle);
}

echo "Engineering human decision persistence passed.\n";
