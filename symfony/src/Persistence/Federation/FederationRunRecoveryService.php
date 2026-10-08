<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\FederationRecoveryClassifier;
use Throwable;

/**
 * Tenant-scoped operator recovery. It may reconcile durable COMPLETED Action
 * receipts but NEVER requeue, resubmit or replay an external side effect.
 */
final readonly class FederationRunRecoveryService
{
    public function __construct(
        private Connection $db,
        private ActiveModuleResolver $modules,
        private FederationRecoveryClassifier $classifier,
        private FederationExternalActionReceiptReconciler $receipts,
    ) {}

    /**
     * @return array{run_id:string,run_state:string,attention_count:int,recoverable_count:int,steps:list<array<string,mixed>>}
     */
    public function inspect(TenantContext $actor, string $runId, int $staleSeconds = 900): array
    {
        $this->authorized($actor, $runId);
        if ($staleSeconds < 60 || $staleSeconds > 86400) {
            throw new DomainException('Federation recovery interval is out of range.');
        }
        $org = $actor->organizationId()->value();
        $run = $this->db->fetchAssociative(
            'SELECT state FROM cos_federation_runs
             WHERE organization_id = :org AND run_id = :run',
            ['org' => $org, 'run' => $runId],
        );
        if (!$run) {
            throw new DomainException('Federation Run unavailable in tenant.');
        }
        $steps = $this->db->fetchAllAssociative(
            'SELECT step_id, side_effect_level, state, updated_at,
                    idempotency_key, result_reference
             FROM cos_federation_steps
             WHERE organization_id = :org AND run_id = :run
             ORDER BY step_id',
            ['org' => $org, 'run' => $runId],
        );
        $now = time();
        $results = [];
        $attention = 0;
        $recoverable = 0;
        foreach ($steps as $step) {
            $id = (string) $step['step_id'];
            if ($step['side_effect_level'] !== 'external') {
                $state = $step['state'] === 'completed' ? 'read_only_completed' : 'unsupported_non_action_step';
                $diagnostic = ['state' => $state, 'attention' => $state !== 'read_only_completed',
                    'action_id' => null];
            } else {
                $actions = $this->db->fetchAllAssociative(
                    'SELECT id, status FROM cos_actions
                     WHERE organization_id = :org AND idempotency_key = :key',
                    ['org' => $org, 'key' => 'fed:' . $step['idempotency_key']],
                );
                $attempts = [];
                // Duplicate Action keys are a conflict, not a chance to choose a winner.
                if (count($actions) === 1) {
                    $attempts = $this->db->fetchAllAssociative(
                        'SELECT attempt, status FROM cos_action_attempts
                         WHERE organization_id = :org AND action_id = :id
                         ORDER BY attempt',
                        ['org' => $org, 'id' => $actions[0]['id']],
                    );
                }
                $then = strtotime((string) $step['updated_at'] . ' UTC');
                $age = $then === false ? 86400 : max(0, $now - $then);
                $diagnostic = $this->classifier->classify(
                    (string) $step['state'],
                    $step['result_reference'] !== null ? (string) $step['result_reference'] : null,
                    $actions, $attempts, $age, $staleSeconds,
                );
                if ($diagnostic['state'] === 'verify_completed_receipt') {
                    // For a completed Step this operation only re-attests;
                    // no row is changed and no handler runs.
                    try {
                        $check = $this->receipts->reconcile($actor, $runId, $id);
                        $verified = $check['status'] === 'completed';
                        $diagnostic = ['state' => $verified ? 'verified_completed' : 'receipt_integrity_failure',
                            'attention' => !$verified, 'action_id' => $check['action_id']];
                    } catch (Throwable) {
                        $diagnostic = ['state' => 'receipt_integrity_failure',
                            'attention' => true, 'action_id' => null];
                    }
                }
            }
            if ($diagnostic['attention']) ++$attention;
            if ($diagnostic['state'] === 'ready_to_reconcile') ++$recoverable;
            $results[] = [
                'step_id' => $id,
                'step_state' => $step['state'],
                'recovery_state' => $diagnostic['state'],
                'attention_required' => $diagnostic['attention'],
                'action_id' => $diagnostic['action_id'],
            ];
        }
        return ['run_id' => $runId, 'run_state' => (string) $run['state'],
            'attention_count' => $attention, 'recoverable_count' => $recoverable,
            'steps' => $results];
    }

    /**
     * One bounded, explicit, idempotent operator sweep. Only COMPLETED Action
     * receipts are candidates. The canonical reconciler independently attests
     * Policy/Approval/input/attempts before committing a step completion.
     *
     * @return array{reconciled:int,report:array<string,mixed>}
     */
    public function reconcileVerified(TenantContext $actor, string $runId): array
    {
        $report = $this->inspect($actor, $runId);
        $reconciled = 0;
        foreach ($report['steps'] as $step) {
            if ($step['recovery_state'] !== 'ready_to_reconcile') {
                continue;
            }
            try {
                $outcome = $this->receipts->reconcile($actor, $runId, (string) $step['step_id']);
                if ($outcome['status'] === 'completed') {
                    ++$reconciled;
                }
            } catch (Throwable) {
                // Race, stale receipt, approval withdrawal or concurrency error:
                // preserve the claim; never turn this into a new execution.
            }
        }
        return ['reconciled' => $reconciled, 'report' => $this->inspect($actor, $runId)];
    }

    private function authorized(TenantContext $actor, string $runId): void
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)
            || !preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $runId)
            || !$this->modules->isEnabled($actor->organizationId()->value(), 'federation')) {
            throw new DomainException('Federation recovery requires authorized, enabled tenant module.');
        }
    }
}
