<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/app/Kernel/Agent/AgentDefinition.php';
require $root . '/app/Kernel/Agent/AgentResult.php';
require $root . '/app/Kernel/Agent/Service/StructuredDecisionValidator.php';

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Service\StructuredDecisionValidator;

$validator = new StructuredDecisionValidator();
$agent = new AgentDefinition(
    'test_agent', '1', 'system', 'prompt', 'schema', ['sales.create_task'],
    maxActionsPerRun: 0,
);

$base = [
    'decision' => 'NOOP',
    'reason' => 'Read-only test.',
    'confidence' => 0.9,
    'proposed_actions' => [],
    'evidence' => [],
];
$result = $validator->validate($base, $agent);
if ($result->proposedActions !== []) throw new RuntimeException('Zero-action definition must accept empty proposed_actions.');

$failed = false;
try {
    $validator->validate(array_replace($base, ['proposed_actions' => [['type' => 'sales.create_task', 'parameters' => []]]]), $agent);
} catch (\InvalidArgumentException $exception) {
    $failed = str_contains($exception->getMessage(), 'at most 0 actions');
}
if (!$failed) throw new RuntimeException('Code-owned maxActionsPerRun=0 was not enforced.');

$agent = new AgentDefinition(
    'test_agent', '1', 'system', 'prompt', 'schema', ['sales.create_task'],
    maxActionsPerRun: 1,
);
$failed = false;
try {
    $validator->validate(array_replace($base, ['proposed_actions' => [['type' => 'sales.change_stage', 'parameters' => []]]]), $agent);
} catch (\InvalidArgumentException $exception) {
    $failed = str_contains($exception->getMessage(), 'not allowed');
}
if (!$failed) throw new RuntimeException('Action allowlist was not enforced.');

fwrite(STDOUT, "Sales V0.7.4 agent runtime unit contract passed.\n");
