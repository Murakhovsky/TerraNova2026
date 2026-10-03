<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$stage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringQaStageExecutor.php');
$progression = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringAutonomousProgressionService.php');

foreach ([
    'commitChecks',
    "ci['state'] === 'PENDING'",
    'ArtifactType::TEST_PLAN',
    'ArtifactType::QA_REPORT',
    'ReadyForHumanApprovalEvidence',
    'hasOpenCritical',
    'acceptanceCriteriaVerified',
] as $needle) {
    if (!str_contains($stage, $needle)) throw new RuntimeException('QA stage missing '.$needle);
}
if (!str_contains($progression, 'AgentRole::QA')) throw new RuntimeException('Autonomous progression does not run QA.');
if (!str_contains($progression, 'for ($step = 0; $step < 12; ++$step)')) throw new RuntimeException('Autonomous progression lacks a hard safety step limit.');

echo "Engineering QA stage passed.\n";
