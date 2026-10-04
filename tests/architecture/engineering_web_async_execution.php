<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root.'/symfony/src/Web/Engineering/EngineeringFeatureController.php');
$messenger = (string) file_get_contents($root.'/symfony/config/packages/messenger.yaml');
$compose = (string) file_get_contents($root.'/docker-compose.yml');
$workflowStore = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringWorkflowStore.php');
$decisionService = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringHumanDecisionService.php');
$featureStore = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringFeatureStore.php');

foreach ([
    'RunEngineeringFeatureCommand',
    'queueImmediate(',
    'markImmediate(',
    'answerAndPrepareResume(',
] as $needle) {
    if (!str_contains($controller, $needle)) {
        throw new RuntimeException('Engineering Web async execution missing '.$needle);
    }
}

foreach ([
    '$this->orchestrator->start(',
    '$this->continue->continueFeature(',
    '$this->decisions->answerAndResume(',
] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('Engineering Web must not execute long-running agent work synchronously: '.$forbidden);
    }
}

foreach ([
    "'App\\Application\\Engineering\\Command\\RunEngineeringFeatureCommand': engineering_immediate",
    'MESSENGER_ENGINEERING_IMMEDIATE_TRANSPORT_DSN',
    'engineering-immediate-worker:',
    'messenger:consume","engineering_immediate',
] as $needle) {
    if (!str_contains($messenger.$compose, $needle)) {
        throw new RuntimeException('Engineering immediate transport boundary missing '.$needle);
    }
}

foreach ([
    "workflow_type = 'ENGINEERING'",
    'ENGINEERING_IMMEDIATE',
] as $needle) {
    if (!str_contains($workflowStore, $needle)) {
        throw new RuntimeException('Engineering queue/immediate isolation missing '.$needle);
    }
}

foreach ([
    'appendPreviousContext',
    'answerAndPrepareResume',
] as $needle) {
    if (!str_contains($decisionService.$featureStore, $needle)) {
        throw new RuntimeException('Engineering async human-decision context persistence missing '.$needle);
    }
}

echo "Engineering Web async execution contract passed.\n";
