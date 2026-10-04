<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$provider = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Context/EngineeringStandardsProvider.php');
$manager = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Manager/EngineeringManagerAnalysisService.php');
$architect = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringArchitectStageExecutor.php');
$developer = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringDeveloperStageExecutor.php');
$reviewer = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringReviewerStageExecutor.php');
$qa = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringQaStageExecutor.php');

foreach ([
    'architecture-standard.md',
    'coding-standard.md',
    'security-standard.md',
    'testing-standard.md',
    'git-standard.md',
    'definition-of-done.md',
    'agent-permissions.md',
    'learning-loop.md',
] as $needle) {
    if (!str_contains($provider, $needle)) throw new RuntimeException('Engineering standards provider missing '.$needle);
}

foreach ([$manager,$architect,$developer,$reviewer,$qa] as $consumer) {
    if (!str_contains($consumer, 'engineering_standards')) throw new RuntimeException('Engineering role does not receive canonical standards.');
}

echo "Engineering standards contract passed.\n";
