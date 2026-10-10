<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\FederationStepCursor;

/**
 * Explicitly triggered, one-transition-per-call linear Action orchestration.
 * Uses canonical Policy/Action/Approval and immutable Plan ordering, never
 * Agent/Tool dispatch, bypass approval, or automatic external replay.
 */
final readonly class FederationSequentialOrchestrator
{
    public function __construct(
        private Connection $db,
        private ActiveModuleResolver $modules,
        private FederationGoalStore $goals,
        private FederationPlanApprovalEvidenceReader $approval,
        private FederationStepCursor $cursor,
        private FederationExternalActionSubmission $submission,
        private FederationExternalActionReceiptReconciler $receipts,
        private FederationRunFinalizer $finalizer,
    ) {}

    /** @return array{run_id:string,state:string} */
    public function start(TenantContext $actor, string $runId, string $planId, string $approvalActionId): array
    {
        $this->authorized($actor);
        $this->goals->startApprovedRun($actor, $runId, $planId, $approvalActionId);
        // Starting an Execution never submits an external Domain Action.
        return ['run_id' => $runId, 'state' => 'pending'];
    }

    /**
     * One safe progression per authenticated call: begin, reconcile ONE claimed
     * Action, submit ONE next approved step, or finalize a fully complete Run.
     * No background scheduler or automatic retry is installed by this adapter.
     *
     * @return array{run_id:string,state:string,step_id:?string,action_id:?string}
     */
    public function advance(TenantContext $actor, string $runId, string $approvalActionId): array
    {
        $this->authorized($actor);
        self::identifier($runId);
        $org = $actor->organizationId()->value();
        $run = $this->db->fetchAssociative(
            'SELECT r.goal_id, r.plan_id, r.state AS run_state, r.revision,
                    p.state AS plan_state, p.spec_version, p.plan_json
             FROM cos_federation_runs r
             JOIN cos_federation_plans p
               ON p.organization_id = r.organization_id AND p.plan_id = r.plan_id
             WHERE r.organization_id = :org AND r.run_id = :run',
            ['org' => $org, 'run' => $runId],
        );
        if (!$run || $run['plan_state'] !== 'approved') {
            throw new DomainException('Federation Run or its approved Plan is unavailable.');
        }
        $goal = $this->goals->specification($actor, (string) $run['goal_id']);
        if ($goal === null || $goal->version !== (int) $run['spec_version']) {
            throw new DomainException('Federation approved Goal specification is stale.');
        }
        $this->approval->requireApproval(
            $actor, $approvalActionId, $goal->goalId, (string) $run['plan_id'],
            $goal->version, (string) $run['plan_json'], $goal->ownerId,
        );
        $result = static fn (string $state, ?string $stepId = null, ?string $actionId = null): array =>
            ['run_id' => $runId, 'state' => $state, 'step_id' => $stepId, 'action_id' => $actionId];

        if ($run['run_state'] === 'completed') {
            return $result('completed');
        }
        if ($run['run_state'] === 'pending') {
            $this->goals->transitionRun($actor, $runId, 'pending', 'running', (int) $run['revision']);
            return $result('running');
        }
        if ($run['run_state'] !== 'running') {
            return $result('manual_reconciliation_required');
        }

        $snapshot = json_decode((string) $run['plan_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || !array_is_list($snapshot['steps'] ?? null)) {
            throw new DomainException('Federation approved Plan snapshot is invalid.');
        }
        $persisted = $this->goals->run($actor, $runId);
        if ($persisted === null) {
            throw new DomainException('Federation Run disappeared during progression.');
        }
        $selected = $this->cursor->select($snapshot['steps'], $persisted['steps']);
        // Validate every predecessor afresh. "completed" flags alone cannot
        // authorize a dependent external Action.
        foreach ($selected['completed'] as $completedStepId) {
            $proof = $this->receipts->reconcile($actor, $runId, $completedStepId);
            if ($proof['status'] !== 'completed') {
                return $result('manual_reconciliation_required', $completedStepId, $proof['action_id']);
            }
        }

        $stepId = $selected['step_id'];
        if ($selected['state'] === 'complete') {
            $this->finalizer->finalize($actor, $runId, (int) $run['revision']);
            return $result('completed');
        }
        if ($selected['state'] === 'ambiguous') {
            return $result('manual_reconciliation_required', $stepId);
        }
        if ($selected['state'] === 'claimed') {
            $receipt = $this->receipts->reconcile($actor, $runId, (string) $stepId);
            if ($receipt['status'] === 'completed') {
                return $result('step_completed', $stepId, $receipt['action_id']);
            }
            if (in_array($receipt['status'], ['ambiguous', 'manual_reconciliation_required'], true)) {
                return $result('manual_reconciliation_required', $stepId, $receipt['action_id']);
            }
            return $result('awaiting_action', $stepId, $receipt['action_id']);
        }
        $submitted = $this->submission->submit($actor, $runId, (string) $stepId, $approvalActionId);
        return $result('awaiting_human_approval', $stepId, $submitted['action_id']);
    }

    private function authorized(TenantContext $actor): void
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)
            || !$this->modules->isEnabled($actor->organizationId()->value(), 'federation')) {
            throw new DomainException('Federation orchestration requires an enabled tenant module and manager permission.');
        }
    }

    private static function identifier(string $id): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $id)) {
            throw new DomainException('Invalid Federation Run identifier.');
        }
    }
}
