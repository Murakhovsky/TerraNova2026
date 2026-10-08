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
echo "Federation sequential cursor passed: immutable order, claimed state, predecessor and topology safety.\n";
