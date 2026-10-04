<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringHumanDecisionService.php');
$featureStore = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringFeatureStore.php');
$featureRecord = (string) file_get_contents($root.'/symfony/src/Persistence/Doctrine/Entity/Engineering/EngineeringFeatureRecord.php');
$continue = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringContinueService.php');
$command = (string) file_get_contents($root.'/symfony/src/Command/EngineeringDecisionCommand.php');

foreach ([
    'humanDecisions->answer',
    'resumeAfterHumanDecision',
    'appendPreviousContext',
    'managerStage->execute',
    'logicalAttempt',
    'Selected option is not offered for this human decision.',
    'offersOption',
] as $needle) {
    if (!str_contains($service.$featureStore.$featureRecord, $needle)) {
        throw new RuntimeException('Human resume service missing '.$needle);
    }
}

foreach ([
    "previous_context",
    'features->request',
] as $needle) {
    if (!str_contains($featureStore.$featureRecord.$continue, $needle)) {
        throw new RuntimeException('Persisted human decision resume context missing '.$needle);
    }
}

if (!str_contains($command, 'cos:engineering:decision')) throw new RuntimeException('Human decision CLI command missing.');

echo "Engineering human resume service passed.\n";
