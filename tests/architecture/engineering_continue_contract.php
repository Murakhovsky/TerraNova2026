<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringContinueService.php');
$command = (string) file_get_contents($root.'/symfony/src/Command/EngineeringContinueCommand.php');

foreach (['QA_PLANNING', 'QA_PENDING', 'REVIEW_PENDING', 'DEVELOPMENT_RUNNING', 'ARCHITECTURE_PENDING', 'hasRunningRole'] as $needle) {
    if (!str_contains($service, $needle)) throw new RuntimeException('Engineering continue service missing '.$needle);
}
if (!str_contains($command, 'cos:engineering:continue')) throw new RuntimeException('Engineering continue CLI command missing.');

echo "Engineering continue contract passed.\n";
