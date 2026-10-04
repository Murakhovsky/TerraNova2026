<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringLearningService.php');
$artifact = (string) file_get_contents($root.'/symfony/src/Engineering/Domain/Artifact/ArtifactType.php');
$docs = (string) file_get_contents($root.'/docs/06-ai-agents/engineering/learning-loop.md');

foreach ([
    'defect_id',
    'root_cause',
    'failed_role',
    'missed_gate',
    'new_rule',
    'regression_test',
    'eval_case',
    'documentation_update',
] as $needle) {
    if (!str_contains($service, $needle)) throw new RuntimeException('Engineering learning contract missing '.$needle);
}
if (!str_contains($artifact, 'ENGINEERING_LEARNING')) throw new RuntimeException('Engineering learning artifact type is missing.');
if (!str_contains($docs, 'Feedback → правило → regression → eval')) throw new RuntimeException('Engineering learning standard is missing.');

echo "Engineering learning contract passed.\n";
