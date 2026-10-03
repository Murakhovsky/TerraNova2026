<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$stage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringArchitectStageExecutor.php');
$progression = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringAutonomousProgressionService.php');

foreach ([
    'ArtifactType::FEATURE_SPEC',
    'ArtifactType::CONTEXT_MAP',
    'ArtifactType::ARCHITECTURE_DECISION',
    'ArtifactType::IMPLEMENTATION_PLAN',
    'ARCHITECTURE_PENDING',
    'agentRuns->start',
    'agentRuns->complete',
] as $needle) {
    if (!str_contains($stage, $needle)) throw new RuntimeException('Architect stage missing '.$needle);
}
if (!str_contains($progression, 'AgentRole::PRINCIPAL_ARCHITECT')) throw new RuntimeException('Autonomous progression does not run Architect.');

echo "Engineering Architect stage passed.\n";
