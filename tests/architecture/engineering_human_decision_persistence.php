<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$managerStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringManagerStageExecutor.php');
$architectStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringArchitectStageExecutor.php');
$developerStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringDeveloperStageExecutor.php');
$reviewerStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringReviewerStageExecutor.php');
$qaStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringQaStageExecutor.php');
$store = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringHumanDecisionStore.php');

foreach ([
    'WorkflowDirectiveType::REQUEST_HUMAN_DECISION',
    'humanDecisions->create',
    "'status' => 'OPEN'",
    'historyForFeature',
    'HumanDecisionRecord',
    'findOneBy',
    "'selected_option'",
    "'requested_by_agent'",
    "'resume_state'",
    'answeredHumanDecisions',
] as $needle) {
    if (!str_contains($managerStage.$architectStage.$developerStage.$reviewerStage.$qaStage.$store, $needle)) {
        throw new RuntimeException('Human decision persistence missing '.$needle);
    }
}

echo "Engineering human decision persistence passed.\n";
