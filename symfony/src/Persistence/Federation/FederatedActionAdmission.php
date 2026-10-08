<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\FederatedActionAdmissionInterface;
use Kernel\Module\CanonicalCapabilityCatalog;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;

/**
 * Last-moment worker admission for external Federation Actions.
 * A completed plan Approval is NOT enough: this specific Action needs its own
 * independent human Approval, latest Policy and immutable input snapshot.
 */
final readonly class FederatedActionAdmission implements FederatedActionAdmissionInterface
{
    public function __construct(
        private Connection $db,
        private CanonicalCapabilityCatalog $catalog,
        private FederationPlanApprovalEvidenceReader $planApprovals,
    ) {}

    public function assertAuthorized(Action $action): void
    {
        $this->verify($action, false);
    }

    /**
     * Read-only receipt attestation. A COMPLETED canonical Action is never
     * re-executed; it must have exactly one successful persisted attempt.
     */
    public function assertCompletedReceipt(Action $action): void
    {
        if ($action->status !== ActionStatus::Completed) {
            throw new DomainException('Federation receipt must originate from a completed Action.');
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT attempt, status FROM cos_action_attempts
             WHERE organization_id = :org AND action_id = :id',
            ['org' => $action->organizationId, 'id' => $action->id],
        );
        $status = $this->db->fetchOne(
            'SELECT status FROM cos_actions WHERE organization_id = :org AND id = :id',
            ['org' => $action->organizationId, 'id' => $action->id],
        );
        if ($status !== 'COMPLETED' || count($rows) !== 1
            || (int) $rows[0]['attempt'] !== 1 || $rows[0]['status'] !== 'COMPLETED') {
            throw new DomainException('Federation Action receipt has no single successful canonical attempt.');
        }
        $this->verify($action, true);
    }

    private function verify(Action $action, bool $completedReceipt): void
    {
        if (!is_string($action->idempotencyKey)
            || !preg_match('/^fed:[a-f0-9]{64}$/', $action->idempotencyKey)
            || !in_array($action->status, $completedReceipt
                ? [ActionStatus::Completed] : [ActionStatus::Queued, ActionStatus::Running], true)
            || $action->sourceType !== 'USER'
            || $action->sourceId === ''
            || $action->executionMode !== 'APPROVAL_REQUIRED') {
            throw new DomainException('Federation Action provenance, key or status is not trusted.');
        }
        $org = $action->organizationId;
        $stepKey = substr($action->idempotencyKey, 4);
        $row = $this->db->fetchAssociative(
            'SELECT r.goal_id, r.plan_id, r.state AS run_state,
                    p.state AS plan_state, p.spec_version, p.plan_json,
                    g.owner_id, g.current_spec_version,
                    s.step_id, s.state AS step_state,
                    s.capability_id, s.capability_version, s.side_effect_level
             FROM cos_federation_steps s
             INNER JOIN cos_federation_runs r
               ON r.organization_id = s.organization_id AND r.run_id = s.run_id
             INNER JOIN cos_federation_plans p
               ON p.organization_id = r.organization_id AND p.plan_id = r.plan_id
             INNER JOIN cos_federation_goals g
               ON g.organization_id = r.organization_id AND g.goal_id = r.goal_id
             WHERE s.organization_id = :org AND s.idempotency_key = :key',
            ['org' => $org, 'key' => $stepKey],
        );
        if (!$row || !in_array($row['run_state'], ['running', 'waiting'], true)
            || $row['plan_state'] !== 'approved'
            || !in_array($row['step_state'], $completedReceipt ? ['claimed', 'completed'] : ['claimed'], true)
            || $row['owner_id'] !== $action->sourceId
            || (int) $row['current_spec_version'] !== (int) $row['spec_version']) {
            throw new DomainException('Federation Action has no active approved and claimed tenant step.');
        }
        $plan = json_decode((string) $row['plan_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($plan) || !is_array($plan['steps'] ?? null)) {
            throw new DomainException('Federation approved Plan JSON is invalid.');
        }
        $step = null;
        foreach ($plan['steps'] as $part) {
            if (($part['id'] ?? null) === $row['step_id']) {
                if ($step !== null) throw new DomainException('Duplicate step in approved Plan.');
                $step = $part;
            }
        }
        $contract = $this->catalog->describe((string) $row['capability_id']);
        if ($contract === null || !is_array($step)
            || $contract->version !== $row['capability_version']
            || $contract->sideEffectLevel !== 'external'
            || $contract->sideEffectLevel !== $row['side_effect_level']
            || $contract->approvalPolicy !== 'required'
            || $contract->idempotency !== 'required'
            || $contract->executionBinding !== 'action:' . $action->type
            || ($step['execution_binding'] ?? null) !== $contract->executionBinding
            || ($step['capability_id'] ?? null) !== $contract->id
            || ($step['capability_version'] ?? null) !== $contract->version
            || ($step['owner_domain'] ?? null) !== $contract->ownerDomain
            || !is_array($step['input'] ?? null)) {
            throw new DomainException('Federation Action capability drift or invalid approved step.');
        }
        $input = $step['input'];
        (new CapabilityJsonInputValidator())->validate($contract, $input);
        if (($input['target_type'] ?? null) !== $action->targetType
            || ($input['target_id'] ?? null) !== $action->targetId
            || !is_array($input['parameters'] ?? null)
            || self::canonical($input['parameters']) !== self::canonical($action->parameters)) {
            throw new DomainException('External Action target or parameters differ from immutable plan.');
        }

        // Re-check completed plan approval for every actual worker attempt.
        $planActions = $this->db->fetchAllAssociative(
            "SELECT id FROM cos_actions WHERE organization_id = :org
             AND type = 'cos.federation.plan.approval'
             AND target_type = 'cos_federation_plan' AND target_id = :plan
             AND status = 'COMPLETED'",
            ['org' => $org, 'plan' => $row['plan_id']],
        );
        if (count($planActions) !== 1) {
            throw new DomainException('Federation plan has no unique canonical completed approval.');
        }
        $this->planApprovals->requireForOrganization(
            $org, (string) $planActions[0]['id'], (string) $row['goal_id'],
            (string) $row['plan_id'], (int) $row['spec_version'],
            (string) $row['plan_json'], (string) $row['owner_id'], 'COMPLETED',
        );

        $policy = $this->db->fetchOne(
            'SELECT decision FROM cos_policy_evaluations
             WHERE organization_id = :org AND action_id = :id
             ORDER BY evaluated_at DESC, id DESC LIMIT 1',
            ['org' => $org, 'id' => $action->id],
        );
        if ($policy !== 'APPROVAL_REQUIRED') {
            throw new DomainException('External Federation Action has no human approval policy evidence.');
        }
        $decisions = $this->db->fetchAllAssociative(
            'SELECT status, decided_by_type, decided_by_id, expires_at, decided_at
             FROM cos_approvals WHERE organization_id = :org AND action_id = :id',
            ['org' => $org, 'id' => $action->id],
        );
        if (count($decisions) !== 1 || $decisions[0]['status'] !== 'APPROVED'
            || $decisions[0]['decided_by_type'] !== 'USER'
            || !is_string($decisions[0]['decided_by_id'])
            || $decisions[0]['decided_by_id'] === ''
            || $decisions[0]['decided_by_id'] === $action->sourceId
            || $decisions[0]['decided_at'] === null
            || ($decisions[0]['expires_at'] !== null
                && strtotime((string) $decisions[0]['expires_at'] . ' UTC') <= time())) {
            throw new DomainException('External Federation Action lacks independent human authorization.');
        }

        // ActionService.recoverStale may requeue a failed/stale worker globally.
        // Even if retried, the second attempt cannot reach the Sales handler.
        $attempts = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM cos_action_attempts
             WHERE organization_id = :org AND action_id = :id',
            ['org' => $org, 'id' => $action->id],
        );
        if ($attempts > 1) {
            throw new DomainException('Second external Federation Action worker attempt is forbidden.');
        }
    }

    private static function canonical(array $input): string
    {
        $sort = static function (array $values) use (&$sort): array {
            if (!array_is_list($values)) ksort($values);
            foreach ($values as $key => $value) {
                if (is_array($value)) $values[$key] = $sort($value);
            }
            return $values;
        };
        return json_encode($sort($input), JSON_THROW_ON_ERROR);
    }
}
