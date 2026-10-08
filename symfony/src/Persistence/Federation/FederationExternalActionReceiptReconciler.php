<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/**
 * Reconciles durable canonical Action receipts without triggering any
 * re-execution. Only first-attempt COMPLETED canonical Action can complete
 * the corresponding Federation external step.
 */
final readonly class FederationExternalActionReceiptReconciler
{
    public function __construct(
        private Connection $db,
        private FederationGoalStore $goals,
    ) {}

    /** @return array{status:string,action_id:?string} */
    public function reconcile(TenantContext $actor, string $runId, string $stepId): array
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Federation receipt reconciliation requires tenant manager.');
        }
        foreach ([$runId, $stepId] as $id) {
            if (!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $id)) {
                throw new DomainException('Invalid Federation Run/Step identifier.');
            }
        }
        $org = $actor->organizationId()->value();
        $step = $this->db->fetchAssociative(
            'SELECT s.state, s.side_effect_level, s.idempotency_key, s.result_reference
             FROM cos_federation_steps s
             INNER JOIN cos_federation_runs r
               ON r.organization_id = s.organization_id AND r.run_id = s.run_id
             WHERE s.organization_id = :org AND s.run_id = :run AND s.step_id = :step',
            ['org' => $org, 'run' => $runId, 'step' => $stepId],
        );
        if (!$step || $step['side_effect_level'] !== 'external') {
            throw new DomainException('Receipt is not for a tenant-owned external Federation step.');
        }
        if ($step['state'] === 'completed') {
            return ['status' => 'completed', 'action_id' => self::actionId($step['result_reference'])];
        }
        if ($step['state'] !== 'claimed' && $step['state'] !== 'ambiguous') {
            return ['status' => (string) $step['state'], 'action_id' => null];
        }
        $actions = $this->db->fetchAllAssociative(
            'SELECT id, status FROM cos_actions
             WHERE organization_id = :org AND idempotency_key = :key',
            ['org' => $org, 'key' => 'fed:' . $step['idempotency_key']],
        );
        if (count($actions) !== 1) {
            return ['status' => 'manual_reconciliation_required', 'action_id' => null];
        }
        $action = $actions[0];
        $id = (string) $action['id'];
        if ($step['result_reference'] !== null
            && $step['result_reference'] !== 'action:' . $id) {
            throw new DomainException('Federation Step receipt references another Action.');
        }
        if ($action['status'] === 'COMPLETED' && $step['state'] === 'claimed') {
            $attempt = $this->db->fetchAllAssociative(
                'SELECT attempt, status FROM cos_action_attempts
                 WHERE organization_id = :org AND action_id = :id',
                ['org' => $org, 'id' => $id],
            );
            if (count($attempt) === 1 && (int) $attempt[0]['attempt'] === 1
                && $attempt[0]['status'] === 'COMPLETED') {
                $this->goals->finishStep($actor, $runId, $stepId, 'completed', 'action:' . $id);
                return ['status' => 'completed', 'action_id' => $id];
            }
            return ['status' => 'manual_reconciliation_required', 'action_id' => $id];
        }
        if ($action['status'] === 'FAILED' && $step['state'] === 'claimed') {
            $this->goals->finishStep($actor, $runId, $stepId, 'ambiguous', 'action:' . $id);
            return ['status' => 'ambiguous', 'action_id' => $id];
        }
        return ['status' => (string) $action['status'], 'action_id' => $id];
    }

    private static function actionId(mixed $receipt): ?string
    {
        return is_string($receipt) && str_starts_with($receipt, 'action:')
            ? substr($receipt, 7) : null;
    }
}
