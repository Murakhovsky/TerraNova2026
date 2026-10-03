<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$stage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringDeveloperStageExecutor.php');
$progression = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringAutonomousProgressionService.php');

foreach ([
    'commitChanges',
    'openPullRequest',
    'ArtifactType::DEVELOPMENT_RESULT',
    'ArtifactType::DEVELOPER_HANDOFF',
    'ArtifactType::ARCHITECTURE_DOCUMENTATION',
    'assertArchitectureGate',
    'requireArchitectureRevalidation',
    'predate Principal Architect V0.1',
    'filesAtRevision',
    'mergeChanges',
    'working_revision',
    'assertDeveloperChangeEvidence',
    'architectureDocumentationEvidenceError',
    'pending_architecture_documentation',
    'files_to_modify',
    'DEVELOPMENT_RUNNING',
    'tests_run',
    'requireRepositoryConfiguration',
    'ARCHITECTURE_REVIEW_REQUIRED',
    'SPECIFICATION_REVIEW_REQUIRED',
    'SECURITY_REVIEW_REQUIRED',
    'COMPLETED_WITH_LIMITATIONS',
] as $needle) {
    if (!str_contains($stage, $needle)) throw new RuntimeException('Developer stage missing '.$needle);
}
if (!str_contains($progression, 'AgentRole::DEVELOPER')) throw new RuntimeException('Autonomous progression does not run Developer.');

echo "Engineering Developer stage passed.\n";
