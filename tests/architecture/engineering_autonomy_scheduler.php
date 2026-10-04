<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$scheduler = (string) file_get_contents($root.'/symfony/src/Scheduler/CosScheduleProvider.php');
$handler = (string) file_get_contents($root.'/symfony/src/Application/Engineering/Command/ContinueEngineeringWorkflowsCommandHandler.php');
$store = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringWorkflowStore.php');

foreach (['ContinueEngineeringWorkflowsCommand', 'engineeringAutonomyEnabled', ".' minutes'"] as $needle) {
    if (!str_contains($scheduler, $needle)) throw new RuntimeException('Engineering scheduler missing '.$needle);
}
foreach (['queueForOrganization', 'organizationId', 'continueFeature', "'QA_PLANNING'", "'QA_PENDING'"] as $needle) {
    if (!str_contains($handler.$store, $needle)) throw new RuntimeException('Engineering scheduler runtime missing '.$needle);
}
foreach ([
    "CASE f.priority WHEN 'P0' THEN 0 WHEN 'P1' THEN 1 WHEN 'P2' THEN 2 WHEN 'P3' THEN 3 ELSE 9 END",
    "addOrderBy('w.started_at', 'ASC')",
    "'queue_policy' => 'priority_fifo'",
    "'worker_concurrency' => 1",
] as $needle) {
    if (!str_contains($handler.$store, $needle)) throw new RuntimeException('Engineering priority queue contract missing '.$needle);
}

echo "Engineering autonomy scheduler passed.\n";
