<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Workflow\Model\WorkflowInstance;
use Platform\Orchestration\Goal\GoalWorkflowBindingGuard;
use LogicException;

/**
 * Trusted preflight boundary between persisted, approved plan snapshot and Workflow.
 * Does not dispatch or modify approval state. Calling code must source actual
 * capability grants from canonical Policy/Approval read models, never client payload.
 */
final readonly class FederationWorkflowPreflight
{
    public function __construct(
        private Connection $connection,
        private FederationGoalStore $goals,
        private GoalWorkflowBindingGuard $guard,
        private FederationPlanApprovalEvidenceReader $approvalEvidence,
    ) {}

    /**
     * @return array{goal_id:string,plan_id:string,specification_version:int,workflow_id:string,step_count:int,approval_action_id:string,approval_id:string}
     */
    public function inspect(
        TenantContext $actor,
        string $planId,
        WorkflowInstance $workflow,
        string $approvalActionId,
    ): array {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Only tenant managers can inspect Goal workflow binding.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $planId)) {
            throw new DomainException('Malformed plan identifier.');
        }
        $tenant = $actor->organizationId()->value();
        $row = $this->connection->fetchAssociative(
            'SELECT plan_id, goal_id, spec_version, state, plan_json
             FROM cos_federation_plans WHERE organization_id = :org AND plan_id = :plan',
            ['org' => $tenant, 'plan' => $planId],
        );
        if (!$row || $row['state'] !== 'approved') {
            throw new DomainException('Tenant plan is not approved.');
        }
        $goal = $this->goals->specification($actor, (string) $row['goal_id']);
        if ($goal === null || $goal->version !== (int) $row['spec_version']) {
            throw new LogicException('Plan bound to stale or unavailable Goal specification.');
        }
        $data = json_decode((string) $row['plan_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['steps'] ?? null)) {
            throw new DomainException('Persisted plan is not a valid snapshot.');
        }
        $evidence = $this->approvalEvidence->requireApproval(
            $actor, $approvalActionId, $goal->goalId, $planId, $goal->version, (string) $row['plan_json'],
        );
        $this->guard->assertCompatible($goal, $data['steps'], $workflow, $evidence['approved_capabilities']);
        return [
            'goal_id' => $goal->goalId,
            'plan_id' => (string) $row['plan_id'],
            'specification_version' => $goal->version,
            'workflow_id' => $workflow->workflow->id,
            'step_count' => count($data['steps']),
            'approval_action_id' => $evidence['action_id'],
            'approval_id' => $evidence['approval_id'],
        ];
    }
}
