<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$managerStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringManagerStageExecutor.php');
$architectStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringArchitectStageExecutor.php');
$store = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringHumanDecisionStore.php');

foreach ([
    'WorkflowDirectiveType::REQUEST_HUMAN_DECISION',
    'humanDecisions->create',
    "'status' => 'OPEN'",
] as $needle) {
    if (!str_contains($managerStage.$architectStage.$store, $needle)) {
        throw new RuntimeException('Human decision persistence missing '.$needle);
    }
}

echo "Engineering human decision persistence passed.\n";
