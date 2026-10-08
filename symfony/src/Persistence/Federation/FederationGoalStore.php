<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use InvalidArgumentException;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use LogicException;
use Platform\Orchestration\Goal\ExecutionRunPolicy;
use Platform\Orchestration\Goal\GoalOutcomeEvaluator;
use Platform\Orchestration\Goal\GoalPlanValidator;
use Platform\Orchestration\Goal\GoalSpecification;

/**
 * Durable projection around existing Workflow/Action/Approval engines, NOT a dispatcher.
 * Every query and mutation uses the authenticated TenantContext as organization authority.
 */
final readonly class FederationGoalStore
{
    public function __construct(
        private Connection $db,
        private FederationCapabilityBindingResolver $bindings,
        private GoalPlanValidator $validator,
        private GoalOutcomeEvaluator $evaluator,
        private FederationPlanApprovalEvidenceReader $approvalEvidence,
    ) {
    }

    public function createGoal(TenantContext $actor, GoalSpecification $goal): void
    {
        $this->writer($actor);
        if ($goal->organizationId !== $actor->organizationId()->value()
            || $goal->ownerId !== $actor->userId()->value()) {
            throw new LogicException('Goal specification must belong to the authenticated author and tenant.');
        }
        $this->identifier($goal->goalId);
        if ($goal->version !== 1) {
            throw new InvalidArgumentException('New goals must start at specification version 1.');
        }
        $this->db->transactional(function () use ($goal): void {
            $this->db->insert('cos_federation_goals', [
                'organization_id' => $goal->organizationId,
                'goal_id' => $goal->goalId,
                'owner_id' => $goal->ownerId,
                'current_spec_version' => $goal->version,
                'state' => 'draft',
                'revision' => 1,
                'created_at' => self::now(),
                'updated_at' => self::now(),
            ]);
            $this->db->insert('cos_federation_goal_specs', [
                'organization_id' => $goal->organizationId,
                'goal_id' => $goal->goalId,
                'spec_version' => $goal->version,
                'specification' => self::json($goal->toArray()),
                'created_at' => self::now(),
            ]);
        });
    }

    public function specification(TenantContext $viewer, string $goalId): ?GoalSpecification
    {
        $this->identifier($goalId);
        $row = $this->db->fetchAssociative(
            'SELECT s.specification FROM cos_federation_goal_specs s
             INNER JOIN cos_federation_goals g ON g.organization_id = s.organization_id AND g.goal_id = s.goal_id
             WHERE g.organization_id = :org AND g.goal_id = :goal AND s.spec_version = g.current_spec_version',
            ['org' => $viewer->organizationId()->value(), 'goal' => $goalId],
        );
        if (!$row) {
            return null;
        }
        $goal = GoalSpecification::fromArray(self::decode($row['specification']));
        if ($goal->organizationId !== $viewer->organizationId()->value()) {
            throw new LogicException('Persisted Goal crossed tenant boundary.');
        }
        return $goal;
    }

    /**
     * Safe read model for the Goals workspace. Reads only authenticated tenant rows;
     * manager policy is applied here, not inferred from ExperienceMode.
     *
     * @return list<array<string,mixed>>
     */
    public function listGoals(TenantContext $viewer, int $limit = 50): array
    {
        $this->writer($viewer);
        $limit = max(1, min(50, $limit));
        $rows = $this->db->fetchAllAssociative(
            'SELECT g.goal_id, g.owner_id, g.state, g.current_spec_version, g.created_at, s.specification
             FROM cos_federation_goals g
             INNER JOIN cos_federation_goal_specs s
               ON s.organization_id = g.organization_id AND s.goal_id = g.goal_id
              AND s.spec_version = g.current_spec_version
             WHERE g.organization_id = :org ORDER BY g.created_at DESC LIMIT ' . $limit,
            ['org' => $viewer->organizationId()->value()],
        );
        $goals = [];
        foreach ($rows as $row) {
            $spec = GoalSpecification::fromArray(self::decode((string) $row['specification']));
            $goals[] = [
                'id' => $row['goal_id'],
                'owner' => $row['owner_id'],
                'state' => $row['state'],
                'version' => (int) $row['current_spec_version'],
                'created_at' => $row['created_at'],
                'desired_result' => $spec->desiredResult,
                'criteria' => $spec->criteria,
                'allowed_capabilities' => $spec->allowedCapabilities,
            ];
        }
        return $goals;
    }

    /**
     * Stores a proposal only. It does not approve a plan or execute any step.
     * Capability availability checks do not substitute runtime permission/approval enforcement.
     *
     * @param list<array{id:string,capability_id:string,capability_version:string}> $steps
     * @return array<string,mixed>
     */
    public function proposePlan(
        TenantContext $actor,
        string $planId,
        string $goalId,
        array $steps,
        int $planVersion = 1,
    ): array {
        $this->writer($actor);
        $this->identifier($planId);
        $this->identifier($goalId);
        if ($planVersion < 1) {
            throw new InvalidArgumentException('Plan version must be positive.');
        }
        $goal = $this->specification($actor, $goalId);
        if ($goal === null) {
            throw new DomainException('Goal not found in tenant.');
        }
        // Declared identity is not enough: require a live canonical owning
        // Action handler, tenant-enabled module and authenticated permission.
        $available = $this->bindings->available($actor);
        $validated = $this->validator->validate($goal, $steps, $available);
        if (!$validated['valid']) {
            throw new DomainException('Goal plan is not valid: ' . implode(',', $validated['errors']));
        }

        $this->db->insert('cos_federation_plans', [
            'organization_id' => $actor->organizationId()->value(),
            'plan_id' => $planId,
            'goal_id' => $goalId,
            'spec_version' => $goal->version,
            'plan_version' => $planVersion,
            'state' => 'proposed',
            'plan_json' => self::json(['steps' => $validated['steps'], 'created_by' => $actor->userId()->value()]),
            'created_at' => self::now(),
        ]);
        return ['plan_id' => $planId, 'status' => 'proposed', 'steps' => $validated['steps']];
    }

    /**
     * Called only after existing Policy/Approval runtime changes a plan to approved.
     * Until that integration, plans remain proposed and cannot run.
     */
    public function startApprovedRun(TenantContext $actor, string $runId, string $planId, string $approvalActionId): void
    {
        $this->writer($actor);
        $this->identifier($runId);
        $this->identifier($planId);
        $org = $actor->organizationId()->value();
        $this->db->transactional(function () use ($actor, $org, $runId, $planId, $approvalActionId): void {
            $plan = $this->db->fetchAssociative(
                'SELECT goal_id, spec_version, state, plan_json FROM cos_federation_plans
                 WHERE organization_id = :org AND plan_id = :plan FOR UPDATE',
                ['org' => $org, 'plan' => $planId],
            );
            if (!$plan || $plan['state'] !== 'approved') {
                throw new DomainException('Only an approved tenant-owned plan can start an execution.');
            }
            $specification = $this->specification($actor, (string) $plan['goal_id']);
            if ($specification === null || $specification->version !== (int) $plan['spec_version']) {
                throw new DomainException('Plan belongs to a stale or unavailable Goal specification.');
            }
            $this->approvalEvidence->requireApproval(
                $actor, $approvalActionId, $specification->goalId, $planId,
                $specification->version, (string) $plan['plan_json'], $specification->ownerId,
            );
            $existing = $this->db->fetchOne(
                'SELECT run_id FROM cos_federation_runs
                 WHERE organization_id = :org AND plan_id = :plan LIMIT 1 FOR UPDATE',
                ['org' => $org, 'plan' => $planId],
            );
            if ($existing !== false) {
                throw new DomainException('Plan already has an execution run; replay is blocked.');
            }
            $steps = self::decode($plan['plan_json'])['steps'] ?? [];
            if (!is_array($steps) || $steps === []) {
                throw new DomainException('Approved plan has no executable steps.');
            }
            $this->db->insert('cos_federation_runs', [
                'organization_id' => $org, 'run_id' => $runId,
                'goal_id' => $plan['goal_id'], 'plan_id' => $planId,
                'state' => 'pending', 'revision' => 1,
                'created_at' => self::now(), 'updated_at' => self::now(),
            ]);
            foreach ($steps as $step) {
                $this->identifier((string) $step['id']);
                $this->db->insert('cos_federation_steps', [
                    'organization_id' => $org, 'run_id' => $runId, 'step_id' => $step['id'],
                    'capability_id' => $step['capability_id'],
                    'capability_version' => $step['capability_version'],
                    'side_effect_level' => $step['side_effect_level'],
                    'state' => 'pending', 'attempts' => 0,
                    'idempotency_key' => hash('sha256', $org . "\0" . $runId . "\0" . $step['id']),
                    'updated_at' => self::now(),
                ]);
            }
        });
    }

    /**
     * Atomically records one inert DecisionStep executed by canonical WorkflowEngine.
     * Never use for Tool/Agent/System side effects. A claimed run cannot auto-replay.
     *
     * @param array<string,mixed> $projection Trusted WorkflowEngine projection
     */
    public function completeReadOnlyWorkflowRun(
        TenantContext $actor,
        string $runId,
        string $stepId,
        array $projection,
    ): void {
        $this->writer($actor);
        $this->identifier($runId);
        $this->identifier($stepId);
        $workflowId = $projection['workflow_id'] ?? null;
        if (!is_string($workflowId) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $workflowId)
            || ($projection['run_state'] ?? null) !== 'completed'
            || !is_array($projection['steps'] ?? null)
            || count($projection['steps']) !== 1
            || ($projection['steps'][0]['step_id'] ?? null) !== $stepId
            || ($projection['steps'][0]['state'] ?? null) !== 'completed') {
            throw new DomainException('Unverifiable read-only Workflow completion projection.');
        }
        $org = $actor->organizationId()->value();
        $this->db->transactional(function () use ($org, $runId, $stepId, $workflowId, $projection): void {
            $run = $this->db->fetchAssociative(
                'SELECT state, revision FROM cos_federation_runs
                 WHERE organization_id = :org AND run_id = :run FOR UPDATE',
                ['org' => $org, 'run' => $runId],
            );
            if (!$run || $run['state'] !== 'running' || (int) $run['revision'] !== 2) {
                throw new LogicException('Read-only Workflow run was modified or already finished.');
            }
            $step = $this->db->fetchAssociative(
                'SELECT state, side_effect_level FROM cos_federation_steps
                 WHERE organization_id = :org AND run_id = :run AND step_id = :step FOR UPDATE',
                ['org' => $org, 'run' => $runId, 'step' => $stepId],
            );
            if (!$step || $step['state'] !== 'claimed' || $step['side_effect_level'] !== 'none') {
                throw new DomainException('Completion requires an exclusively claimed inert step.');
            }
            $changed = $this->db->executeStatement(
                'UPDATE cos_federation_steps
                 SET state = :completed, result_reference = :receipt, checkpoint_json = :checkpoint, updated_at = :now
                 WHERE organization_id = :org AND run_id = :run AND step_id = :step AND state = :claimed',
                [
                    'completed' => 'completed', 'receipt' => 'workflow:' . $workflowId . ':' . $stepId,
                    'checkpoint' => self::json($projection), 'now' => self::now(),
                    'org' => $org, 'run' => $runId, 'step' => $stepId, 'claimed' => 'claimed',
                ],
            );
            $updated = $this->db->executeStatement(
                'UPDATE cos_federation_runs
                 SET state = :completed, revision = revision + 1, checkpoint_id = :workflow, updated_at = :now
                 WHERE organization_id = :org AND run_id = :run AND state = :running AND revision = 2',
                [
                    'completed' => 'completed', 'workflow' => $workflowId, 'now' => self::now(),
                    'org' => $org, 'run' => $runId, 'running' => 'running',
                ],
            );
            if ($changed !== 1 || $updated !== 1) {
                throw new LogicException('Concurrent read-only Workflow completion rejected.');
            }
        });
    }

    /** Optimistic lock: no stale worker may overwrite a concurrent run transition. */
    public function transitionRun(
        TenantContext $actor,
        string $runId,
        string $expectedState,
        string $nextState,
        int $expectedRevision,
    ): void {
        $this->writer($actor);
        $this->identifier($runId);
        ExecutionRunPolicy::assertTransition($expectedState, $nextState);
        $count = $this->db->executeStatement(
            'UPDATE cos_federation_runs SET state = :next, revision = revision + 1, updated_at = :now
             WHERE organization_id = :org AND run_id = :run AND state = :previous AND revision = :revision',
            ['next' => $nextState, 'now' => self::now(), 'org' => $actor->organizationId()->value(),
                'run' => $runId, 'previous' => $expectedState, 'revision' => $expectedRevision],
        );
        if ($count !== 1) {
            throw new LogicException('Run transition rejected: missing tenant record or stale revision/state.');
        }
    }

    /** Claims one pending step once. Uncertain external outcomes stay reserved for manual reconciliation. */
    public function claimStep(TenantContext $actor, string $runId, string $stepId): string
    {
        $this->writer($actor);
        $this->identifier($runId);
        $this->identifier($stepId);
        $org = $actor->organizationId()->value();
        return $this->db->transactional(function () use ($org, $runId, $stepId): string {
            $run = $this->db->fetchOne(
                'SELECT state FROM cos_federation_runs WHERE organization_id = :org AND run_id = :run FOR UPDATE',
                ['org' => $org, 'run' => $runId],
            );
            if ($run !== 'running') {
                throw new DomainException('Step can only be claimed in a running execution.');
            }
            $row = $this->db->fetchAssociative(
                'SELECT state, idempotency_key FROM cos_federation_steps
                 WHERE organization_id = :org AND run_id = :run AND step_id = :step FOR UPDATE',
                ['org' => $org, 'run' => $runId, 'step' => $stepId],
            );
            if (!$row || $row['state'] !== 'pending') {
                throw new LogicException('Step is not pending; automatic replay is blocked.');
            }
            $this->db->executeStatement(
                'UPDATE cos_federation_steps SET state = :claimed, attempts = attempts + 1, updated_at = :now
                 WHERE organization_id = :org AND run_id = :run AND step_id = :step AND state = :pending',
                ['claimed' => 'claimed', 'now' => self::now(), 'org' => $org, 'run' => $runId,
                    'step' => $stepId, 'pending' => 'pending'],
            );
            return (string) $row['idempotency_key'];
        });
    }

    /**
     * Record receipts only; use canonical Domain Action/Tool runtime for the actual work.
     * 'ambiguous' is deliberately terminal for automatic replay.
     */
    public function finishStep(
        TenantContext $actor,
        string $runId,
        string $stepId,
        string $outcome,
        ?string $receiptReference,
    ): void {
        $this->writer($actor);
        $this->identifier($runId);
        $this->identifier($stepId);
        if (!in_array($outcome, ['completed', 'failed', 'ambiguous'], true)) {
            throw new InvalidArgumentException('Invalid step outcome.');
        }
        if ($outcome === 'completed' && ($receiptReference === null || trim($receiptReference) === '')) {
            throw new InvalidArgumentException('Completed step requires a durable result receipt.');
        }
        $org = $actor->organizationId()->value();
        $this->db->transactional(function () use ($org, $runId, $stepId, $outcome, $receiptReference): void {
            // Serialize against terminal transitions and reject delayed workers.
            $runState = $this->db->fetchOne(
                'SELECT state FROM cos_federation_runs
                 WHERE organization_id = :org AND run_id = :run FOR UPDATE',
                ['org' => $org, 'run' => $runId],
            );
            if ($runState !== 'running') {
                throw new DomainException('Cannot finish a Federation Step outside a running Run.');
            }
            $step = $this->db->fetchAssociative(
                'SELECT state, side_effect_level FROM cos_federation_steps
                 WHERE organization_id = :org AND run_id = :run AND step_id = :step FOR UPDATE',
                ['org' => $org, 'run' => $runId, 'step' => $stepId],
            );
            if (!$step) {
                throw new DomainException('Step does not exist in tenant.');
            }
            ExecutionRunPolicy::assertTransition((string) $step['state'], $outcome, true);
            if ($outcome === 'failed' && $step['side_effect_level'] !== 'none') {
                throw new DomainException('Side effect might have executed: mark ambiguous and reconcile.');
            }
            $this->db->executeStatement(
                'UPDATE cos_federation_steps SET state = :state, result_reference = :receipt, updated_at = :now
                 WHERE organization_id = :org AND run_id = :run AND step_id = :step AND state = :claimed',
                ['state' => $outcome, 'receipt' => $receiptReference, 'now' => self::now(),
                    'org' => $org, 'run' => $runId, 'step' => $stepId, 'claimed' => 'claimed'],
            );
        });
    }

    /** @param array<string,array{value:mixed,evidence:list<string>}> $observations */
    public function recordEvaluation(
        TenantContext $actor,
        string $evaluationId,
        string $goalId,
        array $observations,
    ): array {
        $this->writer($actor);
        $this->identifier($evaluationId);
        $this->identifier($goalId);
        $spec = $this->specification($actor, $goalId);
        if ($spec === null) {
            throw new DomainException('Goal not found in tenant.');
        }
        $evaluation = $this->evaluator->evaluate($spec, $observations);
        $this->db->insert('cos_federation_evaluations', [
            'organization_id' => $actor->organizationId()->value(),
            'evaluation_id' => $evaluationId, 'goal_id' => $goalId,
            'spec_version' => $spec->version, 'result' => $evaluation['result'],
            'evaluation_json' => self::json($evaluation), 'evaluated_at' => self::now(),
        ]);
        return $evaluation;
    }

    /** @return array<string,mixed>|null */
    public function run(TenantContext $viewer, string $runId): ?array
    {
        $this->identifier($runId);
        $org = $viewer->organizationId()->value();
        $run = $this->db->fetchAssociative(
            'SELECT * FROM cos_federation_runs WHERE organization_id = :org AND run_id = :run',
            ['org' => $org, 'run' => $runId],
        );
        if (!$run) return null;
        $run['steps'] = $this->db->fetchAllAssociative(
            'SELECT step_id, capability_id, capability_version, side_effect_level, state, attempts, idempotency_key, result_reference
             FROM cos_federation_steps WHERE organization_id = :org AND run_id = :run ORDER BY step_id',
            ['org' => $org, 'run' => $runId],
        );
        return $run;
    }

    private function writer(TenantContext $actor): void
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Federation write requires authenticated manager permission.');
        }
    }

    private function identifier(string $value): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $value)) {
            throw new InvalidArgumentException('Invalid federation resource id.');
        }
    }

    /** @param array<string,mixed> $payload */
    private static function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,mixed> */
    private static function decode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new LogicException('Invalid stored federation JSON object.');
        }
        return $data;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s.u');
    }
}
