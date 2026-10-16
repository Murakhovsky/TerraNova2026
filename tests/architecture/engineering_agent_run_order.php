<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$store = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringAgentRunStore.php');

foreach ([
    "['featureId' => \$featureId],\n            ['startedAt' => 'DESC']",
    "['workflowExecutionId' => \$workflowId],\n            ['startedAt' => 'DESC']",
] as $needle) {
    if (!str_contains($store, $needle)) {
        throw new RuntimeException('Engineering AgentRun lists must be newest-first: '.$needle);
    }
}

echo "Engineering AgentRun newest-first ordering contract passed.\n";
