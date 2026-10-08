<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;

/**
 * Pure, fail-closed classification of durable Federation Action recovery.
 * A diagnostic classification NEVER authorizes submitting or replaying work.
 */
final readonly class FederationRecoveryClassifier
{
    /**
     * @param list<array<string,mixed>> $actions
     * @param list<array<string,mixed>> $attempts
     * @return array{state:string,attention:bool,action_id:?string}
     */
    public function classify(
        string $stepState,
        ?string $receipt,
        array $actions,
        array $attempts,
        int $ageSeconds,
        int $staleSeconds = 900,
    ): array {
        if ($ageSeconds < 0 || $staleSeconds < 60) {
            throw new DomainException('Invalid Federation recovery timing.');
        }
        $answer = static fn (string $state, bool $attention, ?string $id = null): array =>
            ['state' => $state, 'attention' => $attention, 'action_id' => $id];

        if ($stepState === 'pending') {
            return $actions === [] && $attempts === []
                ? $answer('ready', false)
                : $answer('unexpected_action_for_pending_step', true);
        }
        if ($stepState === 'completed') {
            return $answer('verify_completed_receipt', false);
        }
        if ($stepState === 'ambiguous' || $stepState === 'failed') {
            return $answer('manual_reconciliation_required', true);
        }
        if ($stepState !== 'claimed') {
            return $answer('unsupported_step_state', true);
        }
        if (count($actions) > 1) {
            return $answer('duplicate_action_idempotency', true);
        }
        if ($actions === []) {
            return $attempts !== []
                ? $answer('orphaned_action_attempts', true)
                : $answer($ageSeconds >= $staleSeconds
                    ? 'orphaned_claim' : 'submission_in_flight', $ageSeconds >= $staleSeconds);
        }
        $action = $actions[0];
        $id = $action['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)
            || ($receipt !== null && $receipt !== 'action:' . $id)) {
            return $answer('mismatched_action_reference', true);
        }
        if (count($attempts) > 1) {
            return $answer('multiple_execution_attempts', true, $id);
        }
        $status = $action['status'] ?? null;
        if ($status === 'PENDING_APPROVAL') {
            return $attempts === []
                ? $answer('awaiting_human_approval', false, $id)
                : $answer('approval_with_execution_attempt', true, $id);
        }
        if ($status === 'QUEUED') {
            return $attempts === []
                ? $answer('queued_for_execution', false, $id)
                : $answer('requeued_external_attempt', true, $id);
        }
        if ($status === 'RUNNING') {
            if (count($attempts) !== 1
                || (int) ($attempts[0]['attempt'] ?? 0) !== 1
                || ($attempts[0]['status'] ?? null) !== 'RUNNING') {
                return $answer('uncertain_running_attempt', true, $id);
            }
            return $answer($ageSeconds >= $staleSeconds
                ? 'stale_running_action' : 'worker_running', $ageSeconds >= $staleSeconds, $id);
        }
        if ($status === 'COMPLETED') {
            if (count($attempts) !== 1
                || (int) ($attempts[0]['attempt'] ?? 0) !== 1
                || ($attempts[0]['status'] ?? null) !== 'COMPLETED') {
                return $answer('unverified_completed_action', true, $id);
            }
            return $answer('ready_to_reconcile', false, $id);
        }
        return $answer('manual_reconciliation_required', true, $id);
    }
}
