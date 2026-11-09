<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $file): string => (string) file_get_contents($root.'/'.$file);
$controller = $read('symfony/src/Engineering/Application/Service/EngineeringDevelopmentAutopilot.php');
$intake = $read('symfony/src/Engineering/Application/Service/EngineeringIssueIntakeService.php');
$developer = $read('symfony/src/Engineering/Application/Service/EngineeringDeveloperStageExecutor.php');
$gateway = $read('symfony/src/Engineering/Infrastructure/Repository/GitHubEngineeringRepositoryGateway.php');
$store = $read('symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringFeatureStore.php');
$services = $read('symfony/config/services.yaml');
$command = $read('symfony/src/Command/EngineeringAutopilotTickCommand.php');

foreach ([
    'EngineeringWorkflowLockInterface', 'openIssuesByLabel', 'activeForOrganization',
    'findByExternalIssueId', 'EngineeringIssueIntakeService', 'EngineeringOrchestrator',
    'COS_ENGINEERING_AUTOPILOT_ENABLED', "if (!\$execute)", 'queue(', "'dry_run'",
] as $needle) {
    if (!str_contains($controller.$services, $needle)) {
        throw new RuntimeException('Missing Autopilot boundary: '.$needle);
    }
}
foreach (['autopilot_mode', 'autopilot_allowed_paths'] as $needle) {
    if (!str_contains($intake, $needle) || !str_contains($developer, $needle)) {
        throw new RuntimeException('Developer path-scoping contract missing: '.$needle);
    }
}
foreach (['/issues?state=open', 'cos:autopilot:ready'] as $needle) {
    if (!str_contains($gateway, $needle)) throw new RuntimeException('Bounded GitHub queue missing '.$needle);
}
if (!str_contains($store, 'externalIssueId')) throw new RuntimeException('Durable deduplication lookup missing.');
if (!str_contains($command, 'InputOption::VALUE_NONE') || !str_contains($command, "'execute'")) {
    throw new RuntimeException('Explicit one-shot execution flag missing.');
}
if (str_contains($controller, 'mergePullRequest(') || str_contains($controller, 'deployProduction(')) {
    throw new RuntimeException('Autopilot admission must never merge or deploy.');
}
echo "Engineering Development Autopilot contract passed.\n";
