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
$productStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringProductRequirementsStageExecutor.php');
$architectStage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringArchitectStageExecutor.php');
$engineeringRunner = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Agent/EngineeringAgentRunner.php');
$openAiClient = (string) file_get_contents($root.'/app/Infrastructure/Llm/OpenAiResponsesStructuredLlmClient.php');

$continue = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringContinueService.php');

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
    if (!str_contains($productStage, $needle)) throw new RuntimeException('Product pre-agent runtime observability missing '.$needle);
}
if (!str_contains($workflowStore, "COALESCE(w.health_status, 'HEALTHY') <> 'STALLED'")) {
    throw new RuntimeException('Engineering scheduler still retries STALLED workflows automatically.');
}
foreach ([
    "w.runtime_reason",
    "\$previousHealth === 'STALLED' && \$previousReason !== ''",
    "\$reason = \$previousReason",
] as $needle) {
    if (!str_contains($workflowStore, $needle)) {
        throw new RuntimeException('Engineering watchdog does not preserve explicit STALLED runtime failures: '.$needle);
    }
}
if (str_contains($continue, "\$this->workflows->touchRuntime(\$workflowId);\n        \$workflow = \$this->workflows->get(\$workflowId);")) {
    throw new RuntimeException('Engineering continue path still emits a false recovery heartbeat before real work begins.');
}
if (!str_contains($continue, "\$next->type !== WorkflowDirectiveType::STOP")) {
    throw new RuntimeException('Engineering continue path still clears explicit runtime failures after STOP directives.');
}

if (!str_contains($architectStage, 'repository.revision_unavailable') || !str_contains($architectStage, "'human_decision_required' => false")) {
    throw new RuntimeException('Architect repository infrastructure failure is not classified as runtime-owned.');
}
if (str_contains($architectStage, "type: 'EXTERNAL_CREDENTIAL'")) {
    throw new RuntimeException('Architect repository infrastructure still asks the user to confirm runtime configuration.');
}

if (!str_contains($engineeringRunner, 'Transport/provider resilience belongs to the LLM adapter.')
    || !str_contains($engineeringRunner, 'EngineeringAgentOutputValidationException')) {
    throw new RuntimeException('Engineering runner does not separate provider retries from structured-output correction retries.');
}
if (!str_contains($openAiClient, "'OpenAI transport error: '")
    || !str_contains($openAiClient, "'OpenAI request failed with HTTP '")) {
    throw new RuntimeException('OpenAI transport diagnostics are still opaque.');
}

echo "Engineering runtime truth and observability contract passed.\n";
