<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$workflowStore = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringWorkflowStore.php');
$workflowEntity = (string) file_get_contents($root.'/symfony/src/Persistence/Doctrine/Entity/Engineering/WorkflowExecutionRecord.php');
$watchCommand = (string) file_get_contents($root.'/symfony/src/Application/Engineering/Command/WatchEngineeringRuntimeCommand.php');
$watchHandler = (string) file_get_contents($root.'/symfony/src/Application/Engineering/Command/WatchEngineeringRuntimeCommandHandler.php');
$scheduler = (string) file_get_contents($root.'/symfony/src/Scheduler/CosScheduleProvider.php');
$messenger = (string) file_get_contents($root.'/symfony/config/packages/messenger.yaml');
$services = (string) file_get_contents($root.'/symfony/config/services.yaml');
$journal = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Observability/EngineeringExecutionJournal.php');
$eventStore = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Observability/DoctrineEngineeringExecutionEventStore.php');
$status = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringStatusService.php');
$migration = (string) file_get_contents($root.'/symfony/migrations/Version20261005103000.php');
$eventMigration = (string) file_get_contents($root.'/symfony/migrations/Version20261005111500.php');
$repositoryDiscovery = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Repository/LocalRepositoryDiscovery.php');
$managerStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringManagerStageExecutor.php');

foreach (['heartbeatAt','healthStatus','stalledAt','runtimeReason','touchRuntime'] as $needle) {
    if (!str_contains($workflowEntity, $needle)) throw new RuntimeException('Workflow runtime entity missing '.$needle);
}
foreach (['refreshRuntimeHealthForOrganization', "'STALLED'", "'STALE'", "'WAITING'", 'heartbeat_at', 'runtime_reason', 'f.status AS feature_status', 'runtime.health_changed'] as $needle) {
    if (!str_contains($workflowStore, $needle)) throw new RuntimeException('Workflow runtime store missing '.$needle);
}
if (str_contains($workflowStore, "'workflow.health_changed'")) {
    throw new RuntimeException('Engineering watchdog still emits duplicate workflow.health_changed events.');
}
foreach (['WatchEngineeringRuntimeCommand', 'refreshRuntimeHealthForOrganization', 'staleAfterSeconds', 'stalledAfterSeconds'] as $needle) {
    if (!str_contains($watchCommand.$watchHandler, $needle)) throw new RuntimeException('Engineering watchdog missing '.$needle);
}
foreach (['engineeringRuntimeWatchdogEnabled', 'WatchEngineeringRuntimeCommand', ".' minutes'"] as $needle) {
    if (!str_contains($scheduler, $needle)) throw new RuntimeException('Engineering runtime scheduler missing '.$needle);
}
if (!str_contains($messenger, "'App\Application\Engineering\Command\WatchEngineeringRuntimeCommand': engineering")) {
    throw new RuntimeException('Engineering runtime watchdog is not routed to engineering transport.');
}
foreach ([
    'COS_ENGINEERING_RUNTIME_WATCHDOG_ENABLED',
    'COS_ENGINEERING_RUNTIME_STALE_SECONDS',
    'COS_ENGINEERING_RUNTIME_STALLED_SECONDS',
    'EngineeringExecutionEventStoreInterface',
] as $needle) {
    if (!str_contains($services, $needle)) throw new RuntimeException('Engineering runtime services missing '.$needle);
}
foreach (['STARTED','COMPLETED','FAILED','hrtime(true)','correlationId'] as $needle) {
    if (!str_contains($journal, $needle)) throw new RuntimeException('Engineering execution journal missing '.$needle);
}
foreach (['cos_engineering_execution_events','forWorkflow','category','action','details','duration_ms'] as $needle) {
    if (!str_contains($eventStore.$eventMigration, $needle)) throw new RuntimeException('Engineering execution event persistence missing '.$needle);
}
foreach (['execution_events','timeline','usageForWorkflow','forWorkflow($workflowId)'] as $needle) {
    if (!str_contains($status, $needle)) throw new RuntimeException('Engineering workflow read model missing '.$needle);
}
foreach (['heartbeat_at','health_status','stalled_at','runtime_reason'] as $needle) {
    if (!str_contains($migration, $needle)) throw new RuntimeException('Engineering runtime migration missing '.$needle);
}
foreach (['maxScannedFiles', 'maxTotalReadBytes', 'maxScanMilliseconds', 'elapsedMilliseconds', 'hrtime(true)'] as $needle) {
    if (!str_contains($repositoryDiscovery, $needle)) throw new RuntimeException('Repository discovery runtime budget missing '.$needle);
}
foreach (['touchRuntime($workflowId)', 'markRuntimeIssue(', 'Repository discovery failed before AgentRun start'] as $needle) {
    if (!str_contains($managerStage, $needle)) throw new RuntimeException('Manager pre-agent runtime observability missing '.$needle);
}

echo "Engineering runtime truth and observability contract passed.\n";
