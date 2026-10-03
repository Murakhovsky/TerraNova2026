<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflow = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringWorkflowStore.php');
$artifact = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringArtifactStore.php');
$record = (string) file_get_contents($root.'/symfony/src/Persistence/Doctrine/Entity/Engineering/WorkflowExecutionRecord.php');

foreach ([
    'EntityManagerInterface',
    'WorkflowExecution::restore',
    'WorkflowTransitionRecord',
    'resumeState()',
] as $needle) {
    if (!str_contains($workflow.$record, $needle)) throw new RuntimeException('Workflow persistence contract missing '.$needle);
}

foreach ([
    "['featureId' => \$featureId, 'type' => \$type->value]",
    'supersede()',
    "hash('sha256'",
    'version() + 1',
] as $needle) {
    if (!str_contains($artifact, $needle)) throw new RuntimeException('Artifact versioning contract missing '.$needle);
}

echo "Engineering persistence contract passed.\n";
