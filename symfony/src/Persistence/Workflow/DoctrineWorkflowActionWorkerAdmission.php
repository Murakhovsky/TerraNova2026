<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\WorkflowActionWorkerAdmissionInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Platform\Workflow\Contract\WorkflowPlanApprovalEvidenceInterface;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowStepSnapshot;
use Platform\Workflow\Model\WorkflowStepSelectionState;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;
use Platform\Workflow\Service\WorkflowStepScheduler;

/**
 * Candidate last-moment admission with verified receipts of ALL completed DAG Steps.
 *
 * READ ONLY. The worker has already claimed canonical Action RUNNING and
 * recorded its first RUNNING attempt before this check. Nothing is executed
 * here. Multi-step completion is deliberately disabled until independent
 * predecessor receipt provenance is available to the worker.
 *
 * Not production-wired. Existing Kernel ActionExecutor must still check the
 * actual Domain Action handler and live module ownership separately.
 */
final readonly class DoctrineWorkflowActionWorkerAdmission implements WorkflowActionWorkerAdmissionInterface
{
    public function __construct(
        private Connection $db,
        private WorkflowPlanApprovalEvidenceInterface $plans,
        private CanonicalCapabilityCatalog $catalog,
        private ActiveModuleResolver $modules,
        private WorkflowPredecessorReceiptVerifier $predecessors,
    ) {}

    public function assertAuthorized(Action $action): void
    {
        if ($action->status !== ActionStatus::Running
            || !is_string($action->idempotencyKey)
            || !preg_match('/^wf:([a-f0-9]{64})$/', $action->idempotencyKey, $matched)
            || $action->sourceType !== 'USER' || trim($action->sourceId) === ''
            || $action->executionMode !== 'APPROVAL_REQUIRED') {
            throw new DomainException('Workflow worker has no trusted first Action claim.');
        }
        $org = $action->organizationId;
        $key = $matched[1];
        $row = $this->db->fetchAssociative(
            'SELECT r.run_id,r.state AS run_state,r.actor_id,r.correlation_id,
                    r.definition_id,r.definition_version,r.definition_json,r.definition_digest,r.input_digest,
                    r.source_plan_id,r.source_plan_digest,r.approval_reference,
                    s.step_id,s.state AS step_state,s.attempts AS step_attempts,
                    s.claim_token,s.claimed_by,s.claim_expires_at,
                    s.capability_id,s.capability_version,s.side_effect_level,s.result_reference
             FROM cos_workflow_steps s
             INNER JOIN cos_workflow_runs r
               ON r.organization_id=s.organization_id AND r.run_id=s.run_id
             WHERE s.organization_id=:org AND s.idempotency_key=:key',
            ['org'=>$org,'key'=>$key],
        );
        if (!$row || $row['run_state'] !== 'running'
            || $row['step_state'] !== 'claimed'
            || (int) $row['step_attempts'] !== 1
            || !is_string($row['claim_token']) || $row['claim_token'] === ''
            || !is_string($row['claimed_by']) || $row['claimed_by'] === ''
            || $row['claim_expires_at'] === null
            || strtotime((string) $row['claim_expires_at'] . ' UTC') <= time()
            || $row['result_reference'] !== null
            || $row['side_effect_level'] !== 'external'
            || $row['actor_id'] !== $action->sourceId
            || $row['correlation_id'] !== $action->correlationId
            || !is_string($row['source_plan_id']) || $row['source_plan_id'] === ''
            || !preg_match('/^[a-f0-9]{64}$/', (string) $row['source_plan_digest'])
            || !is_string($row['approval_reference']) || $row['approval_reference'] === '') {
            throw new DomainException('Workflow worker Action has no live claim and approved Plan binding.');
        }
        $graph = json_decode((string) $row['definition_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($graph) || !array_is_list($graph)
            || !hash_equals((string) $row['definition_digest'], WorkflowDefinitionFingerprint::digest($graph))) {
            throw new DomainException('Workflow immutable graph has been modified.');
        }
        $steps = $this->db->fetchAllAssociative(
            'SELECT step_id,capability_id,capability_version,side_effect_level,state,
                    attempts,revision,idempotency_key,result_reference
             FROM cos_workflow_steps WHERE organization_id=:org AND run_id=:run ORDER BY ordinal_no',
            ['org'=>$org,'run'=>$row['run_id']],
        );
        $snapshots = array_map(static fn(array $step): WorkflowStepSnapshot =>
            WorkflowStepSnapshot::fromPersistedRow($step), $steps);
        $decision = (new WorkflowStepScheduler())->select(
            new WorkflowDefinitionSnapshot((string) $row['definition_id'],
                (string) $row['definition_version'], $graph),
            $snapshots, ['none','external'],
        );
        if ($decision->state !== WorkflowStepSelectionState::CLAIMED
            || $decision->stepId !== $row['step_id']) {
            throw new DomainException('Workflow worker step is not the uniquely claimed valid DAG action.');
        }
        $this->predecessors->assertVerified(
            $action, new WorkflowRunIdentity($org, (string) $row['run_id']),
            (string) $row['input_digest'], $graph, $decision->completed, $steps,
        );

        $pinned = null;
        foreach ($graph as $candidate) {
            if (($candidate['id'] ?? null) !== $row['step_id']) continue;
            if ($pinned !== null) throw new DomainException('Duplicate Workflow Action definition.');
            $pinned = $candidate;
        }
        $capability = $this->catalog->describe((string) $row['capability_id']);
        if (!is_array($pinned) || $capability === null
            || $capability->id !== $row['capability_id']
            || $capability->version !== $row['capability_version']
            || $capability->kind !== 'command'
            || $capability->sideEffectLevel !== 'external'
            || $capability->approvalPolicy !== 'required'
            || $capability->idempotency !== 'required'
            || in_array($capability->lifecycle, ['deprecated','retired'], true)
            || $capability->executionBinding !== 'action:' . $action->type
            || !$this->modules->isEnabled($org, $capability->ownerDomain)
            || ($pinned['capability_id'] ?? null) !== $capability->id
            || ($pinned['capability_version'] ?? null) !== $capability->version
            || ($pinned['owner_domain'] ?? null) !== $capability->ownerDomain
            || ($pinned['execution_binding'] ?? null) !== $capability->executionBinding
            || ($pinned['side_effect_level'] ?? null) !== 'external'
            || !is_array($pinned['input'] ?? null)
            || !is_array($pinned['input']['parameters'] ?? null)
            || ($pinned['input']['target_type'] ?? null) !== $action->targetType
            || ($pinned['input']['target_id'] ?? null) !== $action->targetId
            || self::canonical($pinned['input']['parameters']) !== self::canonical($action->parameters)) {
            throw new DomainException('Worker Action no longer matches a live executable capability or pinned input.');
        }

        // A Plan approval is not a stored "approved" flag; the injected
        // adapter must verify real human decision, latest Policy and digest.
        $this->plans->assertTrusted(
            new WorkflowRunIdentity($org, (string) $row['run_id']),
            (string) $row['source_plan_id'], (string) $row['source_plan_digest'],
            (string) $row['approval_reference'],
        );
        $dbAction = $this->db->fetchAssociative(
            'SELECT id,status,type,target_type,target_id,parameters,source_type,source_id,
                    execution_mode,idempotency_key,correlation_id
             FROM cos_actions WHERE organization_id=:org AND id=:id
               AND idempotency_key=:key',
            ['org'=>$org,'id'=>$action->id,'key'=>$action->idempotencyKey],
        );
        if (!$dbAction || $dbAction['status'] !== 'RUNNING'
            || $dbAction['type'] !== $action->type
            || $dbAction['target_type'] !== $action->targetType
            || $dbAction['target_id'] !== $action->targetId
            || $dbAction['source_type'] !== $action->sourceType
            || $dbAction['source_id'] !== $action->sourceId
            || $dbAction['execution_mode'] !== $action->executionMode
            || $dbAction['correlation_id'] !== $action->correlationId
            || self::canonical(json_decode((string) $dbAction['parameters'], true, 512, JSON_THROW_ON_ERROR))
                !== self::canonical($action->parameters)) {
            throw new DomainException('Worker Action differs from its claimed canonical persisted Action.');
        }
        $attempts = $this->db->fetchAllAssociative(
            'SELECT attempt,status,worker_id,finished_at
             FROM cos_action_attempts WHERE organization_id=:org AND action_id=:id',
            ['org'=>$org,'id'=>$action->id],
        );
        if (count($attempts) !== 1 || (int) $attempts[0]['attempt'] !== 1
            || $attempts[0]['status'] !== 'RUNNING'
            || !is_string($attempts[0]['worker_id']) || trim($attempts[0]['worker_id']) === ''
            || $attempts[0]['finished_at'] !== null) {
            throw new DomainException('Worker Action has been replayed or lost its unique active attempt.');
        }
        $policy = $this->db->fetchOne(
            'SELECT decision FROM cos_policy_evaluations
             WHERE organization_id=:org AND action_id=:id
             ORDER BY evaluated_at DESC,id DESC LIMIT 1',
            ['org'=>$org,'id'=>$action->id],
        );
        if ($policy !== 'APPROVAL_REQUIRED') {
            throw new DomainException('Worker Action no longer satisfies current canonical approval Policy.');
        }
        $approvals = $this->db->fetchAllAssociative(
            'SELECT status,decided_by_type,decided_by_id,decided_at,expires_at
             FROM cos_approvals WHERE organization_id=:org AND action_id=:id',
            ['org'=>$org,'id'=>$action->id],
        );
        if (count($approvals) !== 1 || $approvals[0]['status'] !== 'APPROVED'
            || $approvals[0]['decided_by_type'] !== 'USER'
            || !is_string($approvals[0]['decided_by_id']) || $approvals[0]['decided_by_id'] === ''
            || $approvals[0]['decided_by_id'] === $action->sourceId
            || $approvals[0]['decided_at'] === null
            || ($approvals[0]['expires_at'] !== null
                && strtotime((string) $approvals[0]['expires_at'] . ' UTC') <= time())) {
            throw new DomainException('Worker Action human Approval is missing, expired or self-approved.');
        }
    }

    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $node) use (&$sort): mixed {
            if (!is_array($node)) return $node;
            if (!array_is_list($node)) ksort($node, SORT_STRING);
            foreach ($node as $key => $inner) $node[$key] = $sort($inner);
            return $node;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
