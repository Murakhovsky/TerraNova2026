<?php
declare(strict_types=1);

use Platform\Orchestration\Goal\ExecutionRunPolicy;
use Platform\Orchestration\Goal\GoalSpecification;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$goal = new GoalSpecification('goal-serialization', 'tenant-a', 'actor-a', 'Find customers', [
    ['id' => 'leads', 'operator' => 'at_least', 'expected' => 5],
], ['sales.leads.read'], version: 2, budgetMinorUnits: 1200, currency: 'USD');
if (GoalSpecification::fromArray($goal->toArray())->toArray() !== $goal->toArray()) {
    throw new RuntimeException('GoalSpecification JSON persistence round-trip failed.');
}
if (!ExecutionRunPolicy::canTransition('pending', 'running')
    || ExecutionRunPolicy::canTransition('completed', 'running')
    || ExecutionRunPolicy::canTransition('claimed', 'claimed', true)
    || ExecutionRunPolicy::canAutoRetry('external', 'waiting')
    || ExecutionRunPolicy::canAutoRetry('financial', 'ambiguous')
    || !ExecutionRunPolicy::canAutoRetry('none', 'waiting')) {
    throw new RuntimeException('Federation execution recovery/state-machine invariants failed.');
}
try {
    ExecutionRunPolicy::assertTransition('completed', 'running');
    throw new RuntimeException('Completed run resurrected.');
} catch (InvalidArgumentException) {
}
echo "Federation Goal serialization and execution-state invariants passed.\n";
