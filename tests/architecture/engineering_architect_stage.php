<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$stage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringArchitectStageExecutor.php');
$progression = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringAutonomousProgressionService.php');
$schemaProvider = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Database/DoctrineEngineeringDatabaseSchemaProvider.php');

foreach ([
    'ArtifactType::FEATURE_SPEC',
    'ArtifactType::CONTEXT_MAP',
    'ArtifactType::ARCHITECTURE_DECISION',
    'ArtifactType::IMPLEMENTATION_PLAN',
    'ArtifactType::DEVELOPER_HANDOFF',
    'ArtifactType::ARCHITECTURE_DOCUMENTATION',
    'filesAtRevision',
    'compareRevisions',
    "'repository_diff'",
    "'repository_files'",
    "'database_schema'",
    "'human_decisions'",
    'requireRepositoryReadConfiguration',
    'currentBaseRevision',
    'historyForFeature',
    "'requested_by_agent'",
    'assertDocumentationEvidence',
    'documentation UPDATE requires complete source evidence',
    "'requested_by_agent'",
    'ARCHITECTURE_PENDING',
    'agentRuns->start',
    'agentRuns->complete',
] as $needle) {
    if (!str_contains($stage, $needle)) throw new RuntimeException('Architect stage missing '.$needle);
}
if (!str_contains($progression, 'AgentRole::PRINCIPAL_ARCHITECT')) throw new RuntimeException('Autonomous progression does not run Architect.');
if (!str_contains($schemaProvider, 'get_debug_type($column->getType())')) throw new RuntimeException('Architect DB schema provider must use DBAL 4-safe type introspection.');
if (str_contains($schemaProvider, 'getDefault()')) throw new RuntimeException('Architect DB schema snapshot must not expose column default values.');
if (!str_contains($stage, "'NEEDS_HUMAN_DECISION'")) throw new RuntimeException('Architect stage does not implement canonical human decision gate.');
if (!str_contains($stage, "'repository_revision'")) throw new RuntimeException('Architect stage does not bind decisions to repository revision.');

echo "Engineering Architect stage passed.\n";
