<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringFinalizeService.php');
$coordinator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Workflow/EngineeringWorkflowCoordinator.php');

foreach (['pullRequest(', "['merged']", 'READY_FOR_HUMAN_APPROVAL', 'ArtifactType::FINAL_REPORT', 'merge_revision'] as $needle) {
    if (!str_contains($service.$coordinator, $needle)) throw new RuntimeException('Human merge gate missing '.$needle);
}
if (!str_contains($coordinator, 'HUMAN_MERGE_CONFIRMED')) throw new RuntimeException('Human merge transition audit trigger missing.');

echo "Engineering human merge gate passed.\n";
