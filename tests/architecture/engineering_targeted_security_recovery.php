<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root.'/'.$path);
$service = $read('symfony/src/Engineering/Application/Service/EngineeringSecurityRecoveryService.php');
$continue = $read('symfony/src/Engineering/Application/Service/EngineeringContinueService.php');
$progression = $read('symfony/src/Engineering/Application/Service/EngineeringAutonomousProgressionService.php');
$stage = $read('symfony/src/Engineering/Application/Service/EngineeringArchitectStageExecutor.php');
$routes = $read('symfony/config/routes.yaml');
$ui = $read('symfony/src/Web/Engineering/EngineeringFeatureController.php');
$template = $read('symfony/templates/experience/engineering/feature.html.twig');
$api = $read('symfony/src/Http/Api/V1/Controller/EngineeringController.php');
$messenger = $read('symfony/config/packages/messenger.yaml');
$handler = $read('symfony/src/Application/Engineering/Command/RunEngineeringSecurityRecoveryCommandHandler.php');

foreach ([
    "'state' => 'PREPARED'",
    "'state' => 'QUEUED'",
    "'state' => 'RUNNING'",
    "'state' => 'SECURITY_REVIEW'",
    "'SECURITY_APPROVED_AWAITING_RELEASE'",
    "'state' => 'ARCHITECT_NOT_APPROVED'",
    "'SECURITY_REWORK_REQUIRED'",
    "'state' => 'RELEASED'",
    "'recovery_cycles_max' => 2",
    "'RECOVERY_CYCLE_LIMIT_REACHED'",
    'markRuntimeIssue',
    'SECURITY_REVIEW_REPORT',
    'reviewed_architecture_content_hash',
    'latestIncludingSuperseded',
    'Security recovery is operator-controlled',
    'source_review_artifact_id',
    "EngineeringWorkflowState::DEVELOPMENT_RUNNING",
    "'release_run_allowance' => 8",
    "AgentRole::SECURITY_SPECIALIST",
] as $marker) {
    if (!str_contains($service, $marker)) throw new RuntimeException('Missing Security recovery safety contract: '.$marker);
}
if (!str_contains($service, 'function enqueue(') || !str_contains($service, "['state' => 'QUEUED']")) {
    throw new RuntimeException('Duplicate async Security recovery queue submission is not guarded.');
}
if (str_contains($service, "->progression->continue(")
    || str_contains($service, '->developer->execute(')
    || str_contains($service, '->markImmediate(') === false) {
    throw new RuntimeException('Recovery bypasses independent approval or cannot explicitly release.');
}
if (!str_contains($continue, 'EngineeringSecurityRecoveryService::latestCheckpoint')
    || !str_contains($progression, 'EngineeringSecurityRecoveryService::latestCheckpoint')) {
    throw new RuntimeException('Generic Continue and scheduled autonomous progression do not fence recovery.');
}
if (!str_contains($progression, "release_run_allowance")
    || !str_contains($progression, 'min(8,')) {
    throw new RuntimeException('Recovery release has no bounded, operator-approved run budget.');
}
foreach ([
    'targetedSecurityRecovery',
    'securityRecovery',
    'maxEvidenceRounds',
] as $marker) {
    if (!str_contains($stage, $marker)) throw new RuntimeException('Architect recovery evidence is not scoped: '.$marker);
}
foreach (['/api/engineering/features/{id}/security-recovery', '/admin/engineering/{id}/security-recovery/prepare',
    '/admin/engineering/{id}/security-recovery/execute', '/admin/engineering/{id}/security-recovery/release'] as $path) {
    if (!str_contains($routes, $path)) throw new RuntimeException('Security recovery route missing: '.$path);
}
foreach ([$api, $ui] as $controller) {
    foreach (['securityRecoveryPrepare', 'securityRecoveryExecute', 'securityRecoveryRelease'] as $action) {
        if (!str_contains($controller, $action)) throw new RuntimeException('Security recovery endpoint missing '.$action);
    }
}
if (!str_contains($ui, '$this->csrf->isValid($request)')
    || !str_contains($api, '$this->authorize($request, true)')
    || !str_contains($template, 'Дозволити Development після Security')) {
    throw new RuntimeException('Security recovery is missing manager/CSRF/operator user flow.');
}
if (!str_contains($messenger, 'RunEngineeringSecurityRecoveryCommand')
    || !str_contains($handler, 'executeOnce(')) {
    throw new RuntimeException('Dedicated asynchronous one-shot worker was not wired.');
}
echo "Engineering targeted Security recovery integration contract passed.\n";
