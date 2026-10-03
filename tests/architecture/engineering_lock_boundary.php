<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$orchestrator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringOrchestrator.php');
$lock = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Lock/MySqlEngineeringWorkflowLock.php');

if (!str_contains($orchestrator, 'activeIdForFeature')) throw new RuntimeException('Duplicate active workflow guard missing.');
if (!str_contains($orchestrator, '// External LLM work deliberately runs outside the feature lock.')) throw new RuntimeException('External Agent call must remain outside feature lock.');
if (!str_contains($lock, "'engineering:feature:'.\$featureId.':workflow'")) throw new RuntimeException('Feature lock key contract missing.');

echo "Engineering feature lock boundary passed.\n";
