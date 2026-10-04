<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflow = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Workflow/EngineeringWorkflowCoordinator.php');
$definition = (string) file_get_contents($root.'/symfony/src/Engineering/Domain/Workflow/EngineeringWorkflowDefinition.php');
$ready = (string) file_get_contents($root.'/symfony/src/Engineering/Domain/Workflow/ReadyForHumanApprovalGuard.php');
$qa = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringQaStageExecutor.php');
$finalize = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringFinalizeService.php');
$evaluation = json_decode((string) file_get_contents($root.'/tests/fixtures/engineering/evaluation/v0.1.json'), true, 512, JSON_THROW_ON_ERROR);

foreach ([
    'QA_PLANNING',
    'ARCHITECTURE_PENDING',
    'DEVELOPMENT_RUNNING',
    'REVIEW_PENDING',
    'QA_PENDING',
    'READY_FOR_HUMAN_APPROVAL',
] as $state) {
    if (!str_contains($definition.$workflow, $state)) throw new RuntimeException('V0.1 workflow missing '.$state);
}

foreach ([
    'tenant_isolation_unverified',
    'authorization_unverified',
    'static_analysis_failed_or_missing',
    'required_tests_failed_or_missing',
    'documentation_impact_unchecked',
    'open_major_or_higher_finding',
    'revision_mismatch',
] as $gate) {
    if (!str_contains($ready, $gate)) throw new RuntimeException('V0.1 READY gate missing '.$gate);
}

foreach ([
    'assertQaTestChanges',
    'head_revision',
    'commentReadySummary',
    'TESTS_UPDATED',
    'HUMAN_TEST_REQUIRED',
] as $needle) {
    if (!str_contains($qa, $needle)) throw new RuntimeException('V0.1 QA release contract missing '.$needle);
}

if (!str_contains($finalize, "if (!\$pr['merged']")) throw new RuntimeException('V0.1 DONE must require verified human merge.');
if (count($evaluation['cases'] ?? []) < 30) throw new RuntimeException('V0.1 requires at least 30 engineering evals.');

echo "Engineering V0.1 release contract passed.\n";
