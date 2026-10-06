<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$productStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringProductRequirementsStageExecutor.php');
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
    'AgentRole::QA_PLANNER',
    'decision_fingerprint',
    'human_decision.loop_detected',
] as $needle) {
    if (!str_contains($productStage.$architectStage.$developerStage.$reviewerStage.$qaStage.$store, $needle)) {
        throw new RuntimeException('Human decision persistence missing '.$needle);
    }
}

echo "Engineering human decision persistence passed.\n";
