<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$stage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringQaStageExecutor.php');
$progression = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringAutonomousProgressionService.php');
$coordinator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Workflow/EngineeringWorkflowCoordinator.php');
$workflow = (string) file_get_contents($root.'/symfony/src/Engineering/Domain/Workflow/EngineeringWorkflowDefinition.php');
$validator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Agent/EngineeringAgentOutputValidator.php');

foreach ([
    'EngineeringWorkflowState::QA_PLANNING',
    'executePlanning',
    'executeVerification',
    'commitChecks',
    'pullRequestFiles',
    'commitChanges',
    "ci['state'] === 'PENDING'",
    'ArtifactType::TEST_PLAN',
    'ArtifactType::QA_REPORT',
    'TESTS_UPDATED',
    'HUMAN_TEST_REQUIRED',
    'ReadyForHumanApprovalEvidence',
    'hasOpenCritical',
    'acceptanceCriteriaVerified',
    'requiredCiChecksPassed',
    'assertQaTestChanges',
    'createQaHumanDecision',
    'answeredHumanDecisions',
    'assertFeatureCoverage',
    'head_revision',
    "architecture['content']['gate_status']",
    "str_starts_with(\$path, 'tests/')",
    "str_starts_with(\$path, 'symfony/tests/')",
] as $needle) {
    if (!str_contains($stage.$validator, $needle)) throw new RuntimeException('QA stage missing '.$needle);
}
foreach (['QA_TEST_PLAN_READY','QA_TESTS_UPDATED_REVIEW_REQUIRED','AgentRole::PRINCIPAL_ARCHITECT','AgentRole::REVIEWER'] as $needle) {
    if (!str_contains($coordinator, $needle)) throw new RuntimeException('QA coordinator routing missing '.$needle);
}
if (!str_contains($workflow, "'QA_PLANNING' => [EngineeringWorkflowState::ARCHITECTURE_PENDING")) throw new RuntimeException('QA planning transition is missing.');
if (!str_contains($workflow, "'QA_PENDING' => [EngineeringWorkflowState::REVIEW_PENDING")) throw new RuntimeException('QA test-update re-review transition is missing.');
if (!str_contains($progression, 'AgentRole::QA')) throw new RuntimeException('Autonomous progression does not run QA.');
foreach (['maxStepsPerProgression','maxLogicalAgentRunsPerFeature','escalateAutonomyBudget'] as $needle) { if (!str_contains($progression, $needle)) throw new RuntimeException('Autonomous progression safety budget missing '.$needle); }

echo "Engineering QA stage passed.\n";
