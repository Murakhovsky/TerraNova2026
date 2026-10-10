<?php
declare(strict_types=1);

use Platform\Orchestration\Goal\GoalPlanApprovalRequestFactory;
use Platform\Orchestration\Goal\GoalSpecification;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$goal = new GoalSpecification('goal-approval', 'tenant-approval', 'owner', 'Verified revenue', [
    ['id' => 'revenue', 'operator' => 'at_least', 'expected' => 100],
], ['finance.revenue.read']);
$factory = new GoalPlanApprovalRequestFactory();
$stored = '{"steps":[{"id":"review","capability_id":"finance.revenue.read","capability_version":"1.0.0"}]}';
$first = $factory->create($goal, 'plan-first', $stored, 'owner');
$second = $factory->create($goal, 'plan-first', $stored, 'owner');
if ($first->type !== 'cos.federation.plan.approval'
    || $first->executionMode !== 'APPROVAL_REQUIRED'
    || $first->idempotencyKey !== $second->idempotencyKey
    || $first->parameters['plan_hash'] !== hash('sha256', $stored)
    || $first->parameters['specification_version'] !== 1
    || $first->policyContext['federation']['plan_id'] !== 'plan-first') {
    throw new RuntimeException('Canonical Goal approval request drifted from approval evidence contract.');
}
$reject = static function (callable $attempt): void {
    try { $attempt(); } catch (DomainException) { return; }
    throw new RuntimeException('Unsafe Goal approval request was accepted.');
};
$reject(fn () => $factory->create($goal, 'plan-first', $stored, 'not-owner'));
$reject(fn () => $factory->create($goal, 'plan-first', '{"steps":[]}', 'owner'));
if ($factory->create($goal, 'plan-first', '{"steps":[{"id":"altered"}]}', 'owner')->idempotencyKey === $first->idempotencyKey) {
    throw new RuntimeException('Changed Goal plan reused approval idempotency key.');
}
echo "Federation immutable Goal approval Action proposal contract passed.\n";
