<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$store = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringFindingStore.php');
$reviewer = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringReviewerStageExecutor.php');

foreach (['recordFindings', 'resolveOpenForSource', 'hasOpenCritical', "'status' => 'OPEN'"] as $needle) {
    if (!str_contains($store.$reviewer, $needle)) throw new RuntimeException('Engineering finding lifecycle missing '.$needle);
}

echo "Engineering finding lifecycle passed.\n";
