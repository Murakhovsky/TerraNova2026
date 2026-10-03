<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$content = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringAutonomousProgressionService.php');

foreach (['nextAttempt', 'EngineeringAgentRunStoreInterface', 'AgentRole::PRINCIPAL_ARCHITECT', 'AgentRole::DEVELOPER'] as $needle) {
    if (!str_contains($content, $needle)) throw new RuntimeException('Logical attempt progression missing '.$needle);
}

echo "Engineering logical attempt progression passed.\n";
