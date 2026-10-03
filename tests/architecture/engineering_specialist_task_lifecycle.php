<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'EngineeringArchitectStageExecutor.php' => 'PRINCIPAL_ARCHITECT',
    'EngineeringDeveloperStageExecutor.php' => 'DEVELOPER',
    'EngineeringReviewerStageExecutor.php' => 'REVIEWER',
    'EngineeringQaStageExecutor.php' => 'QA',
];
foreach ($files as $file => $role) {
    $content = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/'.$file);
    if (!str_contains($content, "markRole(\$featureId, AgentRole::".$role)) {
        throw new RuntimeException('Engineering task stage lifecycle missing for '.$role);
    }
}
echo "Engineering specialist task lifecycle passed.\n";
