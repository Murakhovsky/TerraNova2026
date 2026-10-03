<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$status = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringStatusService.php');
$workflowStore = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringWorkflowStore.php');
$findingStore = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringFindingStore.php');

if (!str_contains($status, 'latestIdForFeature')) throw new RuntimeException('Engineering status must retain terminal workflow visibility.');
if (!str_contains($status, "'findings' =>")) throw new RuntimeException('Engineering status must expose findings.');
if (!str_contains($workflowStore, 'public function latestIdForFeature')) throw new RuntimeException('Engineering workflow store missing latest workflow lookup.');
if (!str_contains($findingStore, 'public function forFeature')) throw new RuntimeException('Engineering finding read model missing.');

echo "Engineering status history read model passed.\n";
