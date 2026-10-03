<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringCancelService.php');
$coordinator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Workflow/EngineeringWorkflowCoordinator.php');

foreach (['HUMAN_CANCELLED','EngineeringWorkflowState::CANCELLED','activeIdForFeature','persistTransitions'] as $needle) {
    if (!str_contains($service.$coordinator, $needle)) throw new RuntimeException('Engineering cancel contract missing '.$needle);
}

echo "Engineering cancel contract passed.\n";
