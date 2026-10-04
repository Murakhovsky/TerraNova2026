<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$correlationId = (string) file_get_contents($root.'/app/Kernel/Observability/CorrelationId.php');
$workflow = (string) file_get_contents($root.'/symfony/src/Persistence/Doctrine/Entity/Engineering/WorkflowExecutionRecord.php');
$agentRun = (string) file_get_contents($root.'/symfony/src/Persistence/Doctrine/Entity/Engineering/AgentRunRecord.php');
$migration = (string) file_get_contents($root.'/symfony/migrations/Version20261004213000.php');
$webController = (string) file_get_contents($root.'/symfony/src/Web/Engineering/EngineeringFeatureController.php');
$apiController = (string) file_get_contents($root.'/symfony/src/Http/Api/V1/Controller/EngineeringController.php');

if (!str_contains($correlationId, 'strlen($value) > 128')) {
    throw new RuntimeException('Kernel CorrelationId maximum length is no longer 128; update Engineering trace storage contract.');
}

foreach ([
    'WorkflowExecutionRecord' => $workflow,
    'AgentRunRecord' => $agentRun,
] as $name => $entity) {
    if (!preg_match('/length:\s*128\)\]\s*\n\s*private string \$traceId/', $entity)) {
        throw new RuntimeException($name.' traceId mapping must be VARCHAR(128)-compatible.');
    }
}

foreach ([
    'ALTER TABLE cos_engineering_workflows MODIFY trace_id VARCHAR(128) NOT NULL',
    'ALTER TABLE cos_engineering_agent_runs MODIFY trace_id VARCHAR(128) NOT NULL',
] as $sql) {
    if (!str_contains($migration, $sql)) {
        throw new RuntimeException('Engineering trace-id migration missing: '.$sql);
    }
}

foreach ([$webController, $apiController] as $controller) {
    if (!str_contains($controller, "EngineeringId::generate()")) {
        throw new RuntimeException('Engineering correlation-id construction contract changed unexpectedly.');
    }
}

$generatedEngineeringTraceLength = strlen('engineering:web:decision:') + 36 + 1 + 36;
if ($generatedEngineeringTraceLength > 128) {
    throw new RuntimeException('Generated Engineering web trace exceeds the Kernel CorrelationId storage contract.');
}

echo "Engineering trace id storage contract passed.\n";
