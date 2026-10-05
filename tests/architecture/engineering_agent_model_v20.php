<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$role = (string) file_get_contents($root.'/symfony/src/Engineering/Domain/Agent/AgentRole.php');
$coordinator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Workflow/EngineeringWorkflowCoordinator.php');
$progression = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringAutonomousProgressionService.php');
$product = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringProductRequirementsStageExecutor.php');
$qa = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringQaStageExecutor.php');
$domainPlanner = (string) file_get_contents($root.'/symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainPlanner.php');
$domainRelease = (string) file_get_contents($root.'/symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainReleaseService.php');

foreach ([
    'ENGINEERING_MANAGER',
    'PRODUCT_REQUIREMENTS',
    'QA_PLANNER',
    'PRINCIPAL_ARCHITECT',
    'DEVELOPER',
    'REVIEWER',
    'QA_EXECUTOR',
    'INTEGRATION_RELEASE',
] as $agentRole) {
    if (!str_contains($role, "case ".$agentRole)) {
        throw new RuntimeException('Engineering V2 agent role missing '.$agentRole);
    }
}

foreach ([
    'AgentRole::PRODUCT_REQUIREMENTS',
    'AgentRole::QA_PLANNER',
    'AgentRole::QA_EXECUTOR',
    'product_handoff_required',
] as $needle) {
    if (!str_contains($coordinator.$progression, $needle)) {
        throw new RuntimeException('Feature V2 orchestration missing '.$needle);
    }
}

foreach (['ArtifactType::FEATURE_SPEC','AgentRole::PRODUCT_REQUIREMENTS','applyManagerAnalysis'] as $needle) {
    if (!str_contains($product, $needle)) throw new RuntimeException('Product / Requirements stage missing '.$needle);
}

foreach (['AgentRole::QA_PLANNER','AgentRole::QA_EXECUTOR','phase\' => \'PLAN','phase\' => \'EXECUTION'] as $needle) {
    if (!str_contains($qa, $needle)) throw new RuntimeException('Split QA stage missing '.$needle);
}

if (!str_contains($domainPlanner, 'AgentRole::PRODUCT_REQUIREMENTS') || !str_contains($domainPlanner, 'AgentRole::QA_PLANNER')) {
    throw new RuntimeException('Domain planning does not separate Manager, Product and QA Planner.');
}
if (!str_contains($domainRelease, 'AgentRole::QA_EXECUTOR') || !str_contains($domainRelease, 'AgentRole::INTEGRATION_RELEASE')) {
    throw new RuntimeException('Domain release does not separate QA Executor and Integration & Release.');
}

echo "Engineering V2 eight-role agent model passed.\n";
