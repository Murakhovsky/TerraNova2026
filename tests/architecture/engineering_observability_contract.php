<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflow = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringWorkflowStore.php');
$runs = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringAgentRunStore.php');
$events = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Observability/DoctrineEngineeringExecutionEventStore.php');

foreach ([
    'feature_id',
    'workflow_execution_id',
    'agent_run_id',
    'correlation_id',
    'duration_ms',
] as $needle) {
    if (!str_contains($events, $needle)) throw new RuntimeException('Engineering execution event store missing '.$needle);
}

foreach ([
    'workflow.transition',
    "'agent_role'",
    "'state_from'",
    "'state_to'",
    "'logical_attempt'",
    "'technical_retry'",
    "'revision'",
    "'provider'",
    "'model'",
    "'cost'",
    "'result'",
] as $needle) {
    if (!str_contains($workflow.$runs, $needle)) throw new RuntimeException('Engineering structured observability missing '.$needle);
}

if (!str_contains($runs, 'durationMs($record)')) {
    throw new RuntimeException('Engineering AgentRun completion/failure events must persist duration.');
}

echo "Engineering observability contract passed.\n";
