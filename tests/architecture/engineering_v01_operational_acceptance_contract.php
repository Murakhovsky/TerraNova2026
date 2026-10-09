<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runtime = (string) file_get_contents($root.'/.github/workflows/runtime.yml');
$services = (string) file_get_contents($root.'/symfony/config/services_test.yaml');
$runner = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Acceptance/DeterministicEngineeringAgentRunner.php');
$repository = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Acceptance/DeterministicEngineeringRepositoryGateway.php');
$crash = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Acceptance/EngineeringV01CrashSupport.php');
$integration = (string) file_get_contents($root.'/symfony/tests/integration/engineering_v01_operational.php');
$developer = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringDeveloperStageExecutor.php');

foreach ([
    'Engineering V0.1 operational acceptance',
    'engineering_v01_operational.php',
    'APP_ENV=test',
] as $needle) {
    if (!str_contains($runtime, $needle)) throw new RuntimeException('Runtime CI is missing V0.1 operational acceptance: '.$needle);
}

foreach ([
    'services:',
    'DeterministicEngineeringAgentRunner',
    'DeterministicEngineeringRepositoryGateway',
    'EngineeringV01CrashSupport',
] as $needle) {
    if (!str_contains($services, $needle)) throw new RuntimeException('Test-only Engineering acceptance wiring missing '.$needle);
}

if (!str_contains($runner, "\$task->inputs['repository_files']") || !str_contains($runner, "\$operation = \$exists ? 'UPDATE' : 'CREATE'")) {
    throw new RuntimeException('Recovery fixture Developer must derive CREATE/UPDATE from repository evidence, not logical attempt number.');
}

foreach ([
    "AgentRole::PRODUCT_REQUIREMENTS",
    "AgentRole::QA_PLANNER",
    "AgentRole::PRINCIPAL_ARCHITECT",
    "AgentRole::DEVELOPER",
    "AgentRole::REVIEWER",
    "AgentRole::QA_EXECUTOR",
    "COS_ENGINEERING_FIXTURE_CRASH_ROLE",
] as $needle) {
    if (!str_contains($runner, $needle)) throw new RuntimeException('Deterministic V0.1 runner missing '.$needle);
}

foreach ([
    "'CI'",
    "'Runtime'",
    "'Static Analysis'",
    'markMerged',
] as $needle) {
    if (!str_contains($repository, $needle)) throw new RuntimeException('Deterministic repository acceptance adapter missing '.$needle);
}

foreach ([
    "'success'",
    "'fix-loop'",
    "'human-gate'",
    "'recovery'",
    "'QA_PLANNER' => 'QA_PLANNING'",
    "'PRINCIPAL_ARCHITECT' => 'ARCHITECTURE_PENDING'",
    "'DEVELOPER' => 'DEVELOPMENT_RUNNING'",
    "'REVIEWER' => 'REVIEW_PENDING'",
    "'QA_EXECUTOR' => 'QA_PENDING'",
    'READY_FOR_HUMAN_APPROVAL',
    "'DONE'",
    'backdateRunningAgentRun',
] as $needle) {
    if (!str_contains($integration.$crash, $needle)) throw new RuntimeException('Operational V0.1 acceptance suite missing '.$needle);
}

if (!str_contains($developer, '$isFixingFeatureCreatedFile')) {
    throw new RuntimeException('Developer fix-loop must allow evidence-backed UPDATE of a file created by an earlier feature attempt.');
}

echo "Engineering V0.1 operational acceptance contract passed.\n";
