<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$transition = (string) file_get_contents($root.'/symfony/src/Engineering/Domain/Workflow/WorkflowTransition.php');
$engine = (string) file_get_contents($root.'/symfony/src/Engineering/Domain/Workflow/EngineeringWorkflowEngine.php');
$entity = (string) file_get_contents($root.'/symfony/src/Persistence/Doctrine/Entity/Engineering/WorkflowTransitionRecord.php');
$store = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringWorkflowStore.php');
$audit = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Audit/DoctrineEngineeringAuditQuery.php');
$migration = (string) file_get_contents($root.'/symfony/migrations/Version20261008152000.php');

foreach ([
    'public int $sequence',
    '$workflow->version()',
] as $needle) {
    if (!str_contains($transition.$engine, $needle)) {
        throw new RuntimeException('Engineering transition domain sequence contract missing '.$needle);
    }
}

foreach ([
    'private int $sequenceNo',
    'sequenceNo: $transition->sequence',
] as $needle) {
    if (!str_contains($entity.$store, $needle)) {
        throw new RuntimeException('Engineering transition persistence sequence contract missing '.$needle);
    }
}

foreach ([
    'sequence_no',
    'ROW_NUMBER() OVER',
    'uniq_cos_eng_transition_sequence',
] as $needle) {
    if (!str_contains($migration, $needle)) {
        throw new RuntimeException('Engineering transition sequence migration missing '.$needle);
    }
}

if (!str_contains($audit, 'ORDER BY sequence_no ASC')) {
    throw new RuntimeException('Engineering audit query must order transitions by deterministic workflow sequence.');
}
if (str_contains($store, 'monotonicTransitionTime')) {
    throw new RuntimeException('Engineering transition order must not depend on timestamp precision.');
}

echo "Engineering transition audit order contract passed.\n";
