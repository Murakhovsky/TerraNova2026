<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
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
        private FederatedActionAdmission $admission,
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
            'SELECT id, status, type, target_type, target_id, parameters, source_type,
                    source_id, execution_mode, risk_level, idempotency_key, correlation_id
             FROM cos_actions
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
            // A matching idempotency key is not proof that the Action actually
            // belonged to this immutable Goal plan. Re-check full plan, input,
            // canonical Policy, independent human Approval and one attempt.
            try {
                $params = json_decode((string) $action['parameters'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($params)) {
                    throw new DomainException('Canonical Action parameters are malformed.');
                }
                $completed = new Action(
                    $id, $org, (string) $action['type'],
                    $action['target_type'] !== null ? (string) $action['target_type'] : null,
                    $action['target_id'] !== null ? (string) $action['target_id'] : null,
                    $params, (string) $action['source_type'], (string) $action['source_id'],
                    (string) $action['execution_mode'], (string) $action['risk_level'],
                    (string) $action['idempotency_key'], new \DateTimeImmutable(),
                    ActionStatus::Completed, (string) $action['correlation_id'],
                );
                $this->admission->assertCompletedReceipt($completed);
            } catch (\Throwable) {
                return ['status' => 'manual_reconciliation_required', 'action_id' => $id];
            }
            $this->goals->finishStep($actor, $runId, $stepId, 'completed', 'action:' . $id);
            return ['status' => 'completed', 'action_id' => $id];
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
