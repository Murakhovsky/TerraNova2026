<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$roles = (string) file_get_contents($root.'/symfony/src/Engineering/Domain/Agent/AgentRole.php');
$coordinator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Workflow/EngineeringWorkflowCoordinator.php');
$progression = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringAutonomousProgressionService.php');
$continue = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringContinueService.php');
$manager = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringManagerStageExecutor.php');
$product = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringProductRequirementsStageExecutor.php');
$qaPlanner = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringQaPlannerStageExecutor.php');
$qaExecutor = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringQaExecutorStageExecutor.php');
$runner = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Agent/EngineeringAgentRunner.php');
$capabilities = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Agent/AgentCapabilityRegistry.php');
$assignment = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Agent/EngineeringAgentAssignmentService.php');
$specialists = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringSpecialistStageExecutor.php');
$invalidation = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringArtifactInvalidationService.php');
$domainRelease = (string) file_get_contents($root.'/symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainReleaseService.php');

foreach ([
    'ENGINEERING_MANAGER','PRODUCT_REQUIREMENTS','QA_PLANNER','PRINCIPAL_ARCHITECT',
    'DEVELOPER','REVIEWER','QA_EXECUTOR','INTEGRATION_RELEASE',
    'SECURITY_SPECIALIST','DATABASE_MIGRATION_SPECIALIST','PERFORMANCE_SPECIALIST',
    'DEVOPS_SPECIALIST','DOCUMENTATION_SPECIALIST','API_SPECIALIST',
] as $role) {
    if (!str_contains($roles, "case {$role}")) throw new RuntimeException('Missing Agent Runtime V2 role '.$role);
}

if (!str_contains($roles, '@deprecated compatibility alias')) throw new RuntimeException('Legacy QA compatibility marker is missing.');

foreach ([$coordinator, $progression, $continue] as $runtime) {
    if (preg_match('/AgentRole::QA(?!_)/', $runtime) === 1) {
        throw new RuntimeException('New Feature runtime still routes legacy QA.');
    }
}
if (!str_contains($runner, 'Legacy QA role is read-only compatibility state')) {
    throw new RuntimeException('New legacy QA execution is not hard-blocked.');
}
if (!str_contains($manager, 'EngineeringProductRequirementsStageExecutor')) {
    throw new RuntimeException('Manager compatibility stage is not orchestration-only.');
}
foreach (['ArtifactType::FEATURE_SPEC','AgentRole::PRODUCT_REQUIREMENTS','createFromProductRequirements','applyProductSpecification'] as $needle) {
    if (!str_contains($product, $needle)) throw new RuntimeException('Product requirements stage missing '.$needle);
}
if (!str_contains($qaPlanner, 'executePlanning') || !str_contains($qaExecutor, 'executeVerification')) {
    throw new RuntimeException('QA Planner and QA Executor are not physically separated entrypoints.');
}
foreach (['AgentCapabilityRegistry','Legacy QA role cannot be assigned'] as $needle) {
    if (!str_contains($capabilities.$assignment, $needle)) throw new RuntimeException('Agent assignment policy missing '.$needle);
}
foreach ([
    'SECURITY_REVIEW_REPORT','MIGRATION_REVIEW_REPORT','PERFORMANCE_REVIEW_REPORT',
    'DEVOPS_REVIEW_REPORT','DOCUMENTATION_REPORT','API_REVIEW_REPORT',
] as $needle) {
    if (!str_contains($specialists, $needle)) throw new RuntimeException('Specialist runtime missing '.$needle);
}
foreach (['afterProductRevision','afterArchitectureRevision','afterImplementationRevision'] as $needle) {
    if (!str_contains($invalidation, $needle)) throw new RuntimeException('Artifact invalidation policy missing '.$needle);
}
foreach (['INTEGRATION_MODE','DOMAIN_INTEGRATION_REPORT','DOMAIN_RELEASE_MODE','RELEASE_READINESS_REPORT','AgentRole::QA_EXECUTOR'] as $needle) {
    if (!str_contains($domainRelease, $needle)) throw new RuntimeException('Domain integration/release sequence missing '.$needle);
}

echo "Engineering Agent Runtime V2 completion architecture guard passed.\n";
