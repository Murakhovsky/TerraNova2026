<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Workflow\Contract\WorkflowPlanApprovalEvidenceInterface;
use Platform\Orchestration\Goal\GoalCapabilityPinGuard;
use Platform\Workflow\Contract\WorkflowStepClaimAdmissionInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Model\WorkflowStepSnapshot;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;

/**
 * Read-only external Action claim admission candidate.
 *
 * This is NOT an Action dispatcher, and is intentionally NOT the default DI
 * WorkflowStepClaimAdmissionInterface. The Action must exist in the canonical
 * Action service, be awaiting human Approval or queued with independent
 * Approval, and have zero attempts. The worker still requires actual Approval.
 * The real Action worker still needs its own last-moment Policy/Approval gate.
 */
final readonly class DoctrineWorkflowActionClaimAdmission implements WorkflowStepClaimAdmissionInterface
{
    public function __construct(
        private Connection $db,
        private WorkflowPlanApprovalEvidenceInterface $plans,
        private CanonicalCapabilityCatalog $catalog,
        private ActiveModuleResolver $modules,
    ) {}

    public function assertAuthorized(WorkflowStepClaim $claim, WorkflowStepSnapshot $step): void
    {
        $command = $claim->command;
        $identity = $command->identity;
        $actor = $command->actor;
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)
            || $step->stepId !== $claim->stepId || $step->state !== 'pending'
            || $step->sideEffectLevel !== 'external'
            || !is_string($step->idempotencyKey)
            || !preg_match('/^[a-f0-9]{64}$/', $step->idempotencyKey)) {
            throw new DomainException('Workflow claim is not an authorized pending external Action.');
        }
        $keys = ['org' => $identity->organizationId, 'run' => $identity->runId,
            'step' => $claim->stepId];
        $context = $this->db->fetchAssociative(
            'SELECT r.state AS run_state,r.revision AS run_revision,r.definition_json,r.definition_digest,
                    r.source_plan_id,r.source_plan_digest,r.approval_reference,r.actor_id,r.correlation_id,
                    s.state AS step_state,s.capability_id,s.capability_version,s.side_effect_level,
                    s.idempotency_key,s.attempts AS step_attempts
             FROM cos_workflow_runs r
             JOIN cos_workflow_steps s ON s.organization_id=r.organization_id AND s.run_id=r.run_id
             WHERE r.organization_id=:org AND r.run_id=:run AND s.step_id=:step',
            $keys,
        );
        if (!$context || $context['run_state'] !== 'running'
            || (int) $context['run_revision'] !== $command->expectedRevision
            || $context['step_state'] !== 'pending'
            || (int) $context['step_attempts'] !== 0
            || $context['idempotency_key'] !== $step->idempotencyKey
            || $context['capability_id'] !== $step->capabilityId
            || $context['capability_version'] !== $step->capabilityVersion
            || $context['side_effect_level'] !== 'external'
            || $context['actor_id'] !== $actor->userId()->value()
            || $context['correlation_id'] !== $command->correlationId
            || !is_string($context['source_plan_id']) || $context['source_plan_id'] === ''
            || !is_string($context['approval_reference']) || $context['approval_reference'] === ''
            || !preg_match('/^[a-f0-9]{64}$/', (string) $context['source_plan_digest'])) {
            throw new DomainException('Workflow claim does not match active trusted Run/Plan/Step.');
        }
        $definition = json_decode((string) $context['definition_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($definition) || !array_is_list($definition)
            || !hash_equals((string) $context['definition_digest'],
                WorkflowDefinitionFingerprint::digest($definition))) {
            throw new DomainException('Workflow immutable execution graph was modified.');
        }
        // Current and any future enabled Action claim path must independently
        // re-attest exact pinned contract/schema and input before claim.
        (new GoalCapabilityPinGuard($this->catalog))->assertPlan($definition);
        $approvedStep = null;
        foreach ($definition as $candidate) {
            if (($candidate['id'] ?? null) !== $claim->stepId) continue;
            if ($approvedStep !== null) throw new DomainException('Duplicate Workflow Step binding.');
            $approvedStep = $candidate;
        }
        if (!is_array($approvedStep)
            || ($approvedStep['capability_id'] ?? null) !== $step->capabilityId
            || ($approvedStep['capability_version'] ?? null) !== $step->capabilityVersion
            || ($approvedStep['side_effect_level'] ?? null) !== 'external'
            || !is_array($approvedStep['input'] ?? null)
            || !is_array($approvedStep['input']['parameters'] ?? null)) {
            throw new DomainException('Workflow Action Step diverges from approved immutable definition.');
        }
        $contract = $this->catalog->describe($step->capabilityId);
        if ($contract === null
            || $contract->id !== $step->capabilityId
            || $contract->version !== $step->capabilityVersion
            || $contract->sideEffectLevel !== 'external'
            || $contract->approvalPolicy !== 'required'
            || $contract->idempotency !== 'required'
            || $contract->lifecycle === 'retired'
            || $contract->lifecycle === 'deprecated'
            || $contract->kind !== 'command'
            || !$this->modules->isEnabled($identity->organizationId, $contract->ownerDomain)
            || ($approvedStep['owner_domain'] ?? null) !== $contract->ownerDomain
            || ($approvedStep['execution_binding'] ?? null) !== $contract->executionBinding
            || !str_starts_with($contract->executionBinding, 'action:')) {
            throw new DomainException('Workflow claim capability is not an active executable canonical Action.');
        }
        // Business approval is not a Plan text flag. In particular, the default
        // PlanEvidence port throws, so no production claim can sneak through.
        $this->plans->assertTrusted(
            $identity, (string) $context['source_plan_id'],
            (string) $context['source_plan_digest'], (string) $context['approval_reference'],
        );
        // ActionPolicyService persists through a separate PDO transaction.
        // The caller's canonical Run transaction may have established a MySQL
        // REPEATABLE READ snapshot before that Action was committed.
        // Locking current reads see the newly committed Action, Policy and
        // Approval rather than making an incorrect "missing Action" decision.
        // This does not relax any independent human / Policy / attempt gate.
        $actions = $this->db->fetchAllAssociative(
            'SELECT id,status,type,target_type,target_id,parameters,source_type,source_id,
                    execution_mode,idempotency_key,correlation_id
             FROM cos_actions WHERE organization_id=:org AND idempotency_key=:key FOR UPDATE',
            ['org' => $identity->organizationId, 'key' => 'wf:' . $step->idempotencyKey],
        );
        if (count($actions) !== 1) {
            throw new DomainException('Missing or duplicate canonical Action for Workflow claim.');
        }
        $action = $actions[0];
        if (!preg_match('/^[a-f0-9]{32}$/', (string) $action['id'])
            || !in_array($action['status'], ['PENDING_APPROVAL','QUEUED'], true)
            || $action['source_type'] !== 'USER'
            || $action['source_id'] !== $context['actor_id']
            || $action['execution_mode'] !== 'APPROVAL_REQUIRED'
            || $action['correlation_id'] !== $context['correlation_id']
            || $contract->executionBinding !== 'action:' . $action['type']
            || ($approvedStep['input']['target_type'] ?? null) !== $action['target_type']
            || ($approvedStep['input']['target_id'] ?? null) !== $action['target_id']) {
            throw new DomainException('Canonical Action is not queued with immutable trusted provenance.');
        }
        $actual = json_decode((string) $action['parameters'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($actual)
            || self::canonical($actual) !== self::canonical($approvedStep['input']['parameters'])) {
            throw new DomainException('Canonical Action inputs differ from approved Workflow Plan.');
        }
        $attempts = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM cos_action_attempts WHERE organization_id=:org AND action_id=:action',
            ['org' => $identity->organizationId, 'action' => $action['id']],
        );
        if ($attempts !== 0) {
            throw new DomainException('External Workflow Action was already attempted; no automatic replay.');
        }
        $policy = $this->db->fetchOne(
            'SELECT decision FROM cos_policy_evaluations
             WHERE organization_id=:org AND action_id=:action
             ORDER BY evaluated_at DESC,id DESC LIMIT 1 FOR UPDATE',
            ['org' => $identity->organizationId, 'action' => $action['id']],
        );
        if ($policy !== 'APPROVAL_REQUIRED') {
            throw new DomainException('Latest canonical Policy does not authorize human-approved Action.');
        }
        $decisions = $this->db->fetchAllAssociative(
            'SELECT status,decided_by_type,decided_by_id,decided_at,expires_at
             FROM cos_approvals WHERE organization_id=:org AND action_id=:action FOR UPDATE',
            ['org' => $identity->organizationId, 'action' => $action['id']],
        );
        if (count($decisions) !== 1) {
            throw new DomainException('Canonical Action requires exactly one human Approval record.');
        }
        if ($action['status'] === 'PENDING_APPROVAL') {
            // A claim can reserve the Step before a human accepts the Action.
            // The worker rejects until independently approved, and can never
            // execute before this exclusive Step claim exists.
            if ($decisions[0]['status'] !== 'PENDING'
                || $decisions[0]['decided_at'] !== null
                || $decisions[0]['decided_by_id'] !== null) {
                throw new DomainException('Pre-Approval claim has an invalid pending decision.');
            }
        } elseif ($decisions[0]['status'] !== 'APPROVED'
            || $decisions[0]['decided_by_type'] !== 'USER'
            || !is_string($decisions[0]['decided_by_id']) || $decisions[0]['decided_by_id'] === ''
            || $decisions[0]['decided_by_id'] === $action['source_id']
            || $decisions[0]['decided_at'] === null
            || ($decisions[0]['expires_at'] !== null
                && strtotime((string) $decisions[0]['expires_at'] . ' UTC') <= time())) {
            throw new DomainException('Queued Action has no independent active human Approval.');
        }
    }

    private static function canonical(mixed $node): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            foreach ($value as $key => $inner) $value[$key] = $sort($inner);
            return $value;
        };
        return json_encode($sort($node), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
