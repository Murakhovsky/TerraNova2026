<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$requiredFiles = [
    'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainRuntimeService.php',
    'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainPlanner.php',
    'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainFeatureScheduler.php',
    'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainReleaseService.php',
    'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainContextBuilder.php',
    'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainDriftDetector.php',
    'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainAgentService.php',
    'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainAgentSchemas.php',
    'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainAgentOutputValidator.php',
    'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainConcurrencyGate.php',
    'symfony/src/Engineering/Domain/DomainDevelopment/EngineeringDomainRuntimeEventType.php',
    'symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringDomainStore.php',
    'symfony/src/Http/Api/V1/Controller/EngineeringDomainController.php',
    'symfony/migrations/Version20261005170000.php',
    'symfony/migrations/Version20261005193000.php',
];

foreach ($requiredFiles as $file) {
    if (!is_file($root.'/'.$file)) throw new RuntimeException('Engineering Domain Runtime missing '.$file);
}

$planner = (string) file_get_contents($root.'/symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainPlanner.php');
$scheduler = (string) file_get_contents($root.'/symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainFeatureScheduler.php');
$release = (string) file_get_contents($root.'/symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainReleaseService.php');
$store = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringDomainStore.php');
$migration = (string) file_get_contents($root.'/symfony/migrations/Version20261005170000.php');
$completionMigration = (string) file_get_contents($root.'/symfony/migrations/Version20261005193000.php');
$concurrency = (string) file_get_contents($root.'/symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainConcurrencyGate.php');
$events = (string) file_get_contents($root.'/symfony/src/Engineering/Domain/DomainDevelopment/EngineeringDomainRuntimeEventType.php');
$progression = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringAutonomousProgressionService.php');
$routes = (string) file_get_contents($root.'/symfony/config/routes.yaml');
$services = (string) file_get_contents($root.'/symfony/config/services.yaml');
$schedule = (string) file_get_contents($root.'/symfony/src/Scheduler/CosScheduleProvider.php');
$manager = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringManagerStageExecutor.php');
$architect = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringArchitectStageExecutor.php');
$developer = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringDeveloperStageExecutor.php');
$reviewer = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringReviewerStageExecutor.php');
$qa = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringQaStageExecutor.php');
$gateway = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Repository/EngineeringRepositoryGatewayInterface.php');

foreach ([
    'DOMAIN_SPECIFICATION',
    'DOMAIN_ACCEPTANCE_CRITERIA',
    'DOMAIN_QA_PLAN',
    'DOMAIN_ARCHITECTURE',
    'DOMAIN_ARCHITECTURE_CONSTITUTION',
    'DOMAIN_DECOMPOSITION',
    'MIGRATION_PLAN',
    'INTEGRATION_STRATEGY',
] as $needle) {
    if (!str_contains($planner, $needle)) throw new RuntimeException('Domain planning is missing '.$needle);
}

foreach ([
    'FeatureDependencyGraph',
    'max_parallel_features',
    'reservePaths',
    'ensureIntegrationBranch',
    'DOMAIN_FEATURE',
    'queue(',
    'EngineeringDomainFeatureStatus::COMPLETED',
] as $needle) {
    if (!str_contains($scheduler, $needle)) throw new RuntimeException('Domain scheduler is missing '.$needle);
}

foreach ([
    'DOMAIN_QA_REPORT',
    'DOMAIN_RELEASE_MANIFEST',
    'Domain integration pull request must be merged',
    'configuredBaseBranch',
    'openPullRequest',
] as $needle) {
    if (!str_contains($release, $needle)) throw new RuntimeException('Domain release gate is missing '.$needle);
}

foreach ([
    'cos_engineering_domains',
    'cos_engineering_domain_artifacts',
    'cos_engineering_domain_capabilities',
    'cos_engineering_domain_features',
    'cos_engineering_domain_dependencies',
    'cos_engineering_domain_contracts',
    'cos_engineering_domain_events',
    'cos_engineering_domain_path_reservations',
    'cos_engineering_domain_agent_runs',
] as $table) {
    if (!str_contains($migration, $table)) throw new RuntimeException('Domain migration missing '.$table);
}

foreach ([
    "status='SUPERSEDED'",
    "status='ACTIVE'",
    'contract_key',
    'event_key',
] as $needle) {
    if (!str_contains($store, $needle)) throw new RuntimeException('Domain registry history is missing '.$needle);
}


// TZ §77 — independent Domain concurrency limits with configuration defaults.
foreach (['max_parallel_features','max_parallel_developers','max_parallel_reviews','max_parallel_qa','cos_engineering_domain_runtime_events'] as $needle) {
    if (!str_contains($completionMigration, $needle)) throw new RuntimeException('Domain Runtime completion migration missing '.$needle);
}
foreach (['max_parallel_developers','max_parallel_reviews','max_parallel_qa','EngineeringWorkflowState::DEVELOPMENT_RUNNING','EngineeringWorkflowState::REVIEW_PENDING','EngineeringWorkflowState::QA_PENDING'] as $needle) {
    if (!str_contains($concurrency, $needle)) throw new RuntimeException('Domain concurrency gate missing '.$needle);
}
if (!str_contains($progression, 'domainConcurrency->decision')) throw new RuntimeException('Feature progression does not enforce Domain stage concurrency.');

// TZ §78 — canonical orchestration event vocabulary and lifecycle wiring.
foreach ([
    'DomainCreated','DomainSpecificationReady','DomainDecompositionReady','DomainArchitectureApproved',
    'CapabilityReady','FeatureReady','FeatureStarted','FeatureCompleted','FeatureBlocked',
    'ContractChanged','ArchitectureChanged','DomainIntegrationStarted','DomainIntegrationCompleted',
    'DomainQaStarted','DomainQaCompleted','DomainReleaseReady',
] as $event) {
    if (!str_contains($events, "'".$event."'")) throw new RuntimeException('Domain orchestration event missing '.$event);
}
foreach (['recordRuntimeEvent','runtimeEvents'] as $needle) {
    if (!str_contains($store, $needle)) throw new RuntimeException('Domain runtime event ledger missing '.$needle);
}

if (!str_contains($services, 'EngineeringDomainStoreInterface')) throw new RuntimeException('Domain store DI alias is missing.');
foreach (['/api/engineering/domains', '/plan', '/tick', '/verify', '/approve'] as $needle) {
    if (!str_contains($routes, $needle)) throw new RuntimeException('Domain control-plane route missing '.$needle);
}
foreach (['ContinueEngineeringDomainsCommand', 'engineeringDomainAutonomyEnabled'] as $needle) {
    if (!str_contains($schedule, $needle)) throw new RuntimeException('Domain autonomy scheduler missing '.$needle);
}

foreach ([$manager, $architect, $developer, $reviewer, $qa] as $stage) {
    if (!str_contains($stage, 'DOMAIN_CONTEXT_PACK')) {
        throw new RuntimeException('One Engineering stage does not propagate DOMAIN_CONTEXT_PACK.');
    }
}
foreach (['assertDomainPathPolicy', 'owned_paths', 'shared_paths', 'forbidden_paths'] as $needle) {
    if (!str_contains($developer, $needle)) throw new RuntimeException('Developer Domain path enforcement missing '.$needle);
}
foreach (['configuredRepository()', 'currentBaseRevision(?string $branch = null)', 'configuredBaseBranch', 'ensureBranch', '?string $baseBranch = null'] as $needle) {
    if (!str_contains($gateway, $needle)) throw new RuntimeException('Repository gateway lacks integration branch contract '.$needle);
}

echo "Engineering Domain Runtime V2 architecture passed.\n";
