<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Action\ActionProposal;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;

/**
 * Derives an immutable, tenant-authorized canonical Action intent.
 * Deliberately does NOT enqueue or dispatch it. A submitter must atomically
 * re-check plan, step claim, current Policy and human Approval before queuing.
 */
final readonly class FederationApprovedActionIntentFactory
{
    public function __construct(
        private Connection $db,
        private FederationGoalStore $goals,
        private FederationPlanApprovalEvidenceReader $approvals,
        private FederationCapabilityBindingResolver $bindings,
    ) {}

    public function create(
        TenantContext $actor,
        string $runId,
        string $stepId,
        string $planApprovalActionId,
    ): ActionProposal {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Only authorized tenant managers may prepare federated Actions.');
        }
        foreach ([$runId, $stepId] as $id) {
            if (!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $id)) {
                throw new DomainException('Invalid federated execution identifier.');
            }
        }
        $org = $actor->organizationId()->value();
        $row = $this->db->fetchAssociative(
            'SELECT r.goal_id, r.plan_id, r.state AS run_state,
                    p.state AS plan_state, p.spec_version, p.plan_json,
                    s.state AS step_state, s.capability_id, s.capability_version,
                    s.side_effect_level, s.idempotency_key
             FROM cos_federation_runs r
             INNER JOIN cos_federation_plans p
               ON p.organization_id = r.organization_id AND p.plan_id = r.plan_id
             INNER JOIN cos_federation_steps s
               ON s.organization_id = r.organization_id AND s.run_id = r.run_id
             WHERE r.organization_id = :org AND r.run_id = :run AND s.step_id = :step',
            ['org' => $org, 'run' => $runId, 'step' => $stepId],
        );
        if (!$row || $row['run_state'] !== 'running' || $row['plan_state'] !== 'approved'
            || $row['step_state'] !== 'pending') {
            throw new DomainException('Federated Action requires a pending step in an approved, running plan.');
        }
        $goal = $this->goals->specification($actor, (string) $row['goal_id']);
        if ($goal === null || $goal->version !== (int) $row['spec_version']) {
            throw new DomainException('Goal specification has changed or is unavailable.');
        }
        $this->approvals->requireApproval(
            $actor, $planApprovalActionId, $goal->goalId, (string) $row['plan_id'],
            $goal->version, (string) $row['plan_json'], $goal->ownerId,
        );
        $contract = $this->bindings->requireExecutable($actor, (string) $row['capability_id']);
        if ($contract->version !== $row['capability_version']
            || $contract->sideEffectLevel !== $row['side_effect_level']
            || $contract->sideEffectLevel !== 'external'
            || $contract->approvalPolicy !== 'required'
            || $contract->idempotency !== 'required'
            || !in_array($contract->id, $goal->allowedCapabilities, true)) {
            throw new DomainException('External capability version, permission or action policy changed.');
        }
        $plan = json_decode((string) $row['plan_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($plan) || !is_array($plan['steps'] ?? null)) {
            throw new DomainException('Approved plan is not a valid snapshot.');
        }
        $approvedStep = null;
        foreach ($plan['steps'] as $step) {
            if (($step['id'] ?? null) === $stepId) {
                if ($approvedStep !== null) {
                    throw new DomainException('Duplicate step in approved Plan.');
                }
                $approvedStep = $step;
            }
        }
        if (!is_array($approvedStep)
            || ($approvedStep['capability_id'] ?? null) !== $contract->id
            || ($approvedStep['capability_version'] ?? null) !== $contract->version
            || ($approvedStep['execution_binding'] ?? null) !== $contract->executionBinding
            || ($approvedStep['owner_domain'] ?? null) !== $contract->ownerDomain
            || ($approvedStep['side_effect_level'] ?? null) !== $contract->sideEffectLevel
            || !is_array($approvedStep['input'] ?? null)) {
            throw new DomainException('Approved immutable step does not match its live capability binding.');
        }
        $input = $approvedStep['input'];
        (new CapabilityJsonInputValidator())->validate($contract, $input);
        $actionType = substr($contract->executionBinding, 7);
        return new ActionProposal(
            $actionType,
            (string) $input['target_type'],
            (string) $input['target_id'],
            (array) $input['parameters'],
            'USER',
            $goal->ownerId,
            'APPROVAL_REQUIRED',
            'HIGH',
            (string) $row['idempotency_key'],
            [
                'federation' => [
                    'goal_id' => $goal->goalId, 'plan_id' => $row['plan_id'],
                    'run_id' => $runId, 'step_id' => $stepId,
                    'approval_action_id' => $planApprovalActionId,
                ],
            ],
        );
    }
}
