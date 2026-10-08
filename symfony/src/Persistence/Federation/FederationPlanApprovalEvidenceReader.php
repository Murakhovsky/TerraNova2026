<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantContext;

/**
 * Verifies a plan-level decision against the canonical Action / Approval / Policy
 * tables. A plan.state string or caller-supplied capability list is never enough.
 * This read-only adapter cannot create approvals, queue Actions or dispatch work.
 */
final readonly class FederationPlanApprovalEvidenceReader
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @return array{action_id:string,approval_id:string,approved_capabilities:list<string>}
     */
    public function requireApproval(
        TenantContext $actor,
        string $actionId,
        string $goalId,
        string $planId,
        int $specVersion,
        string $storedPlanJson,
    ): array {
        $org = $actor->organizationId()->value();
        if (!preg_match('/^[a-f0-9]{32}$/', $actionId)) {
            throw new DomainException('Approval Action identifier must be a canonical 32-character id.');
        }
        $action = $this->db->fetchAssociative(
            'SELECT id, organization_id, type, target_type, target_id, parameters,
                    status, execution_mode, source_type, source_id
             FROM cos_actions WHERE organization_id = :org AND id = :id',
            ['org' => $org, 'id' => $actionId],
        );
        if (!$action || $action['type'] !== 'cos.federation.plan.approval'
            || $action['target_type'] !== 'cos_federation_plan'
            || $action['target_id'] !== $planId
            || $action['status'] !== 'QUEUED'
            || $action['execution_mode'] !== 'APPROVAL_REQUIRED') {
            throw new DomainException('Canonical approval Action is unavailable or incompatible.');
        }
        $params = json_decode((string) $action['parameters'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($params) || array_is_list($params)
            || ($params['goal_id'] ?? null) !== $goalId
            || ($params['plan_id'] ?? null) !== $planId
            || ($params['specification_version'] ?? null) !== $specVersion
            || !is_string($params['plan_hash'] ?? null)
            || !hash_equals(hash('sha256', $storedPlanJson), $params['plan_hash'])) {
            throw new DomainException('Approved Action does not match immutable Goal / Plan snapshot.');
        }

        // Latest policy evaluation wins. A later DENIED decision blocks execution.
        $policy = $this->db->fetchOne(
            'SELECT decision FROM cos_policy_evaluations
             WHERE organization_id = :org AND action_id = :action
             ORDER BY evaluated_at DESC, id DESC LIMIT 1',
            ['org' => $org, 'action' => $actionId],
        );
        if ($policy !== 'APPROVAL_REQUIRED') {
            throw new DomainException('Canonical Policy evaluation does not require and permit human approval.');
        }

        $approvals = $this->db->fetchAllAssociative(
            'SELECT id, status, decided_by_type, decided_by_id, expires_at, decided_at
             FROM cos_approvals
             WHERE organization_id = :org AND action_id = :action',
            ['org' => $org, 'action' => $actionId],
        );
        if (count($approvals) !== 1) {
            throw new DomainException('Plan approval requires exactly one canonical human decision.');
        }
        $approval = $approvals[0];
        if ($approval['status'] !== 'APPROVED'
            || $approval['decided_by_type'] !== 'USER'
            || !is_string($approval['decided_by_id']) || $approval['decided_by_id'] === ''
            || $approval['decided_at'] === null
            || ($action['source_type'] === 'USER' && $action['source_id'] === $approval['decided_by_id'])
            || ($approval['expires_at'] !== null
                && strtotime((string) $approval['expires_at'] . ' UTC') <= time())) {
            throw new DomainException('Canonical human approval is missing, expired or self-approved.');
        }
        $plan = json_decode($storedPlanJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($plan) || !array_is_list($plan['steps'] ?? null) || $plan['steps'] === []) {
            throw new DomainException('Approved plan contains no valid executable step snapshot.');
        }
        $capabilities = [];
        foreach ($plan['steps'] as $step) {
            $capability = $step['capability_id'] ?? null;
            if (!is_string($capability) || $capability === '') {
                throw new DomainException('Approved plan step lacks a canonical capability identity.');
            }
            $capabilities[] = $capability;
        }
        return [
            'action_id' => $actionId,
            'approval_id' => (string) $approval['id'],
            'approved_capabilities' => array_values(array_unique($capabilities)),
        ];
    }
}
