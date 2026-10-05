<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$requiredFiles = [
    'symfony/src/Engineering/Domain/DomainDevelopment/DomainDevelopmentStatus.php',
    'symfony/src/Engineering/Domain/DomainDevelopment/DomainDependencyGraph.php',
    'symfony/src/Engineering/Domain/DomainDevelopment/DomainReleaseReadinessEvaluator.php',
    'symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineDomainDevelopmentStore.php',
    'symfony/src/Engineering/Application/Service/DomainDevelopmentCoordinator.php',
    'symfony/src/Engineering/Application/Service/DomainFeatureContextBuilder.php',
    'symfony/src/Engineering/Application/Service/DomainFeatureScheduler.php',
    'symfony/migrations/Version20261005190000.php',
];

foreach ($requiredFiles as $path) {
    if (!is_file($root.'/'.$path)) throw new RuntimeException('Engineering Domain Runtime is missing '.$path);
}

$scheduler = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/DomainFeatureScheduler.php');
foreach ([
    'DomainDependencyGraph',
    'DomainPathReservationPolicy',
    'EngineeringOrchestrator',
    'EngineeringStatusService',
    'claimFeature',
    'architecture_version',
    'domain_runtime',
] as $needle) {
    if (!str_contains($scheduler, $needle)) throw new RuntimeException('Domain scheduler contract missing '.$needle);
}

$coordinator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/DomainDevelopmentCoordinator.php');
foreach ([
    'approveArchitecture',
    'registerContract',
    'recordDomainQa',
    'markReleaseReady',
    'CONTRACT_REVALIDATION_REQUIRED',
] as $needle) {
    if (!str_contains($coordinator, $needle)) throw new RuntimeException('Domain coordinator contract missing '.$needle);
}

$migration = (string) file_get_contents($root.'/symfony/migrations/Version20261005190000.php');
foreach ([
    'cos_engineering_domains',
    'cos_engineering_domain_capabilities',
    'cos_engineering_domain_features',
    'cos_engineering_domain_dependencies',
    'cos_engineering_domain_contracts',
    'cos_engineering_domain_artifacts',
    'cos_engineering_domain_audit',
] as $table) {
    if (!str_contains($migration, $table)) throw new RuntimeException('Domain Runtime migration missing '.$table);
}

echo "Engineering Domain Runtime architecture checks passed\n";
