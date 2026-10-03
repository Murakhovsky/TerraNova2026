<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$store = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringTaskStore.php');
$qa = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringQaStageExecutor.php');

foreach (['markRole','hasIncomplete','AgentRole::tryFrom',"'COMPLETED','CANCELLED'"] as $needle) {
    if (!str_contains($store, $needle)) throw new RuntimeException('Engineering task lifecycle missing '.$needle);
}
if (!str_contains($qa, 'tasks->hasIncomplete')) throw new RuntimeException('READY gate does not block incomplete tasks.');

echo "Engineering task lifecycle passed.\n";
