<?php
declare(strict_types=1);

use Platform\Orchestration\Goal\FederationRecoveryClassifier;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$c = new FederationRecoveryClassifier();
$id = str_repeat('a', 32);
$action = [['id' => $id, 'status' => 'PENDING_APPROVAL']];
$assert = static function (string $expected, string $step, ?string $receipt, array $actions, array $attempts, int $age = 10) use ($c): void {
    $actual = $c->classify($step, $receipt, $actions, $attempts, $age);
    if ($actual['state'] !== $expected) {
        throw new RuntimeException('Federation recovery: expected ' . $expected . ', got ' . $actual['state']);
    }
};
$assert('ready', 'pending', null, [], []);
$assert('submission_in_flight', 'claimed', null, [], [], 40);
$assert('orphaned_claim', 'claimed', null, [], [], 1000);
$assert('awaiting_human_approval', 'claimed', 'action:' . $id, $action, []);
$assert('mismatched_action_reference', 'claimed', 'action:' . str_repeat('b', 32), $action, []);
$assert('multiple_execution_attempts', 'claimed', null, [['id' => $id, 'status' => 'COMPLETED']],
    [['attempt' => 1, 'status' => 'FAILED'], ['attempt' => 2, 'status' => 'COMPLETED']]);
$assert('unverified_completed_action', 'claimed', null, [['id' => $id, 'status' => 'COMPLETED']], []);
$assert('ready_to_reconcile', 'claimed', 'action:' . $id, [['id' => $id, 'status' => 'COMPLETED']],
    [['attempt' => 1, 'status' => 'COMPLETED']]);
$assert('manual_reconciliation_required', 'claimed', null, [['id' => $id, 'status' => 'REJECTED']], []);
$assert('requeued_external_attempt', 'claimed', null, [['id' => $id, 'status' => 'QUEUED']],
    [['attempt' => 1, 'status' => 'FAILED']]);
$assert('worker_running', 'claimed', null, [['id' => $id, 'status' => 'RUNNING']],
    [['attempt' => 1, 'status' => 'RUNNING']], 60);
$assert('stale_running_action', 'claimed', null, [['id' => $id, 'status' => 'RUNNING']],
    [['attempt' => 1, 'status' => 'RUNNING']], 1000);
$assert('manual_reconciliation_required', 'ambiguous', null, [['id' => $id, 'status' => 'COMPLETED']],
    [['attempt' => 1, 'status' => 'COMPLETED']]);
$assert('verify_completed_receipt', 'completed', 'action:' . $id, $action, []);
$assert('duplicate_action_idempotency', 'claimed', null, [$action[0], $action[0]], []);
echo "Federation recovery classifier passed: orphan, approval, terminal, attempts and no-replay states.\n";
