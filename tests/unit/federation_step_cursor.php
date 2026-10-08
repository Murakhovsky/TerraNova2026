<?php
declare(strict_types=1);

use Platform\Orchestration\Goal\FederationStepCursor;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$cursor = new FederationStepCursor();
$plan = [
    ['id' => 'z-first', 'capability_id' => 'sales.create_task', 'capability_version' => '1.0.0', 'side_effect_level' => 'external'],
    ['id' => 'a-second', 'capability_id' => 'sales.create_task', 'capability_version' => '1.0.0', 'side_effect_level' => 'external'],
];
$steps = [
    ['step_id' => 'a-second', 'capability_id' => 'sales.create_task', 'capability_version' => '1.0.0', 'side_effect_level' => 'external', 'state' => 'pending'],
    ['step_id' => 'z-first', 'capability_id' => 'sales.create_task', 'capability_version' => '1.0.0', 'side_effect_level' => 'external', 'state' => 'pending'],
];
$first = $cursor->select($plan, $steps);
if ($first['step_id'] !== 'z-first' || $first['state'] !== 'pending' || $first['completed'] !== []) {
    throw new RuntimeException('Federation scheduler must use approved Plan order, not SQL alphabetical order.');
}
$steps[1]['state'] = 'claimed';
if ($cursor->select($plan, $steps)['state'] !== 'claimed') {
    throw new RuntimeException('Federation scheduler ignored an already claimed external Action.');
}
$steps[1]['state'] = 'completed';
$next = $cursor->select($plan, $steps);
if ($next['step_id'] !== 'a-second' || $next['completed'] !== ['z-first']) {
    throw new RuntimeException('Federation scheduler failed to enforce completed predecessor.');
}
$steps[0]['state'] = 'completed';
if ($cursor->select($plan, $steps)['state'] !== 'complete') {
    throw new RuntimeException('Federation scheduler failed to detect full completion.');
}
$reject = static function (array $approved, array $live, string $reason) use ($cursor): void {
    try {
        $cursor->select($approved, $live);
    } catch (DomainException) {
        return;
    }
    throw new RuntimeException('Federation cursor accepted: ' . $reason);
};
$forged = $steps;
$forged[0]['capability_id'] = 'sales.send_message';
$reject($plan, $forged, 'unapproved capability replacement');
$skipped = $steps;
$skipped[1]['state'] = 'pending';
$reject($plan, $skipped, 'skipped predecessor');
$extra = $steps;
$extra[] = ['step_id' => 'injected'];
$reject($plan, $extra, 'unexpected step');
$unsafe = $plan;
$unsafe[0]['side_effect_level'] = 'financial';
$reject($unsafe, $steps, 'unsupported financial side effect');
$duplicate = $plan;
$duplicate[1]['id'] = $duplicate[0]['id'];
$reject($duplicate, $steps, 'duplicate Plan step id');
$empty = [];
$reject($empty, [], 'empty Plan');
// Explicit branches execute in a safe deterministic serial order, never in parallel.
$dagPlan = [
    ['id' => 'alpha', 'capability_id' => 'sales.create_task', 'capability_version' => '1.0.0',
        'side_effect_level' => 'external', 'depends_on' => []],
    ['id' => 'beta', 'capability_id' => 'sales.create_task', 'capability_version' => '1.0.0',
        'side_effect_level' => 'external', 'depends_on' => []],
    ['id' => 'join', 'capability_id' => 'sales.create_task', 'capability_version' => '1.0.0',
        'side_effect_level' => 'external', 'depends_on' => ['alpha', 'beta']],
];
$dagSteps = [];
foreach (array_reverse($dagPlan) as $definition) {
    $dagSteps[] = ['step_id' => $definition['id'], 'capability_id' => 'sales.create_task',
        'capability_version' => '1.0.0', 'side_effect_level' => 'external', 'state' => 'pending'];
}
if ($cursor->select($dagPlan, $dagSteps)['step_id'] !== 'alpha') {
    throw new RuntimeException('DAG root selection did not follow immutable Plan order.');
}
$dagSteps[2]['state'] = 'completed';
if ($cursor->select($dagPlan, $dagSteps)['step_id'] !== 'beta') {
    throw new RuntimeException('DAG join ran before all prerequisites completed.');
}
$dagSteps[1]['state'] = 'claimed';
$claimed = $cursor->select($dagPlan, $dagSteps);
if ($claimed['state'] !== 'claimed' || $claimed['step_id'] !== 'beta') {
    throw new RuntimeException('Claimed DAG branch must block independent external dispatch.');
}
$concurrent = $dagSteps;
$concurrent[0]['state'] = 'claimed';
$reject($dagPlan, $concurrent, 'concurrent claimed external DAG Actions');
$dagSteps[1]['state'] = 'completed';
if ($cursor->select($dagPlan, $dagSteps)['step_id'] !== 'join') {
    throw new RuntimeException('DAG join not released after both receipts completed.');
}
$premature = $dagSteps;
$premature[1]['state'] = 'pending';
$premature[0]['state'] = 'completed';
$reject($dagPlan, $premature, 'completed join with incomplete prerequisite');
$dagSteps[0]['state'] = 'completed';
if ($cursor->select($dagPlan, $dagSteps)['state'] !== 'complete') {
    throw new RuntimeException('DAG did not finalize after its last node.');
}
$cycle = $dagPlan;
$cycle[0]['depends_on'] = ['join'];
$reject($cycle, $dagSteps, 'cyclic dependency');
$unknownEdge = $dagPlan;
$unknownEdge[2]['depends_on'] = ['missing'];
$reject($unknownEdge, $dagSteps, 'unknown dependency');
$duplicateEdge = $dagPlan;
$duplicateEdge[2]['depends_on'] = ['alpha', 'alpha'];
$reject($duplicateEdge, $dagSteps, 'duplicate prerequisite');
$selfEdge = $dagPlan;
$selfEdge[2]['depends_on'] = ['join'];
$reject($selfEdge, $dagSteps, 'self prerequisite');
$malformed = $dagPlan;
$malformed[1]['depends_on'] = 'alpha';
$reject($malformed, $dagSteps, 'untyped DAG edge');
echo "Federation serialized DAG cursor passed: legacy order, independent branches, join, cycles and unsafe concurrency.\n";
