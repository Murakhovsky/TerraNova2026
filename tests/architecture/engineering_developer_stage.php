<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$stage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringDeveloperStageExecutor.php');
$progression = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringAutonomousProgressionService.php');

foreach ([
    'commitChanges',
    'openPullRequest',
    'ArtifactType::DEVELOPMENT_RESULT',
    'DEVELOPMENT_RUNNING',
    'tests_run',
    'requireRepositoryConfiguration',
] as $needle) {
    if (!str_contains($stage, $needle)) throw new RuntimeException('Developer stage missing '.$needle);
}
if (!str_contains($progression, 'AgentRole::DEVELOPER')) throw new RuntimeException('Autonomous progression does not run Developer.');

echo "Engineering Developer stage passed.\n";
