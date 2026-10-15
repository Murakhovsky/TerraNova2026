<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use App\Persistence\Workflow\FederationWorkflowPlanApprovalEvidence;
use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Action\ActionProposal;
use Kernel\Action\ActionStatus;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Policy\Contract\PolicyRepositoryInterface;
use Kernel\Policy\PolicyDecision;
use Kernel\Policy\Service\ActionPolicyService;
use Kernel\Policy\Service\PolicyContextBuilder;
use Kernel\Policy\Service\PolicyEngine;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Workflow\Model\StepStatus;
use Kernel\Workflow\Model\WorkflowStatus;
use LogicException;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;
use Platform\Workflow\Contract\WorkflowExecutionStoreInterface;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowRunTransition;
use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Model\WorkflowStepCheckpoint;
use Platform\Workflow\Model\WorkflowStepSelectionState;
use Platform\Workflow\Service\WorkflowStepScheduler;

/**
 * Goal application ADAPTER to the one Platform technical Run/Step authority.
 * Only operator-allowlisted NEW plans; no Federation Run/Step INSERT or UPDATE,
 * no independent worker, no automatic retry, no dual writes.
 *
 * A persisted canonical run remains canonical if the rollout flag is removed.
 * Legacy runs stay on their original executor until an audited backfill.
 */
final readonly class FederationCanonicalWorkflowBridge
{
    public function __construct(
        private Connection $db,
        private WorkflowExecutionStoreInterface $workflow,
        private WorkflowStepScheduler $scheduler,
        private FederationGoalStore $goals,
        private FederationWorkflowPlanApprovalEvidence $planEvidence,
        private DoctrineWorkflowActionReceiptVerifier $receipts,
        private FederationPlanApprovalEvidenceReader $approval,
        private CanonicalCapabilityCatalog $capabilities,
        private DomainModuleRegistry $domains,
        private ActiveModuleResolver $modules,
        private PolicyRepositoryInterface $policies,
        private PolicyEngine $policyEngine,
        private PolicyContextBuilder $contexts,
        private ActionPolicyService $actions,
    ) {}

    public function newRunsEnabled(TenantContext $actor): bool
    {
        $organization = getenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
        return is_string($organization) && $organization !== ''
            && hash_equals($organization, $actor->organizationId()->value());
    }

    public function owns(TenantContext $actor, string $runId): bool
    {
        $this->authorized($actor);
        self::identifier($runId);
        return $this->db->fetchOne(
            'SELECT run_id FROM cos_workflow_runs
             WHERE organization_id=:org AND run_id=:run AND definition_id=:definition',
            ['org'=>$actor->organizationId()->value(), 'run'=>$runId,
             'definition'=>'federation.approved-plan'],
        ) !== false;
    }

    /** @return array<string,mixed> */
    public function status(TenantContext $actor, string $runId): array
    {
        $this->authorized($actor);
        $id = new WorkflowRunIdentity($actor->organizationId()->value(), $runId);
        $run = $this->requireRun($id);
        return [
            'run_id'=>$runId, 'plan_id'=>$run->sourcePlanId,
            'state'=>$run->state->value, 'revision'=>$run->revision,
            'execution_authority'=>'platform_workflow',
            'steps'=>array_map(static fn($step): array => [
                'step_id'=>$step->stepId, 'capability_id'=>$step->capabilityId,
                'state'=>$step->state, 'attempts'=>$step->attempts,
                'receipt'=>$step->resultReference,
            ], $this->workflow->steps($id)),
        ];
    }

    /** Starts ONE persisted canonical Run; a transaction-locked Plan fences legacy starts. */
    public function start(TenantContext $actor, string $runId, string $planId, string $approvalId): array
    {
        $this->authorized($actor);
        if (!$this->newRunsEnabled($actor)) {
            throw new DomainException('Canonical Federation new-run rollout is not enabled for this tenant.');
        }
        self::identifier($runId);
        self::identifier($planId);
        $org = $actor->organizationId()->value();
        return $this->db->transactional(function () use ($actor,$org,$runId,$planId,$approvalId): array {
            $plan = $this->db->fetchAssociative(
                'SELECT goal_id,spec_version,state,plan_json FROM cos_federation_plans
                 WHERE organization_id=:org AND plan_id=:plan FOR UPDATE',
                ['org'=>$org,'plan'=>$planId],
            );
            if (!$plan || $plan['state'] !== 'approved') {
                throw new DomainException('Canonical execution requires a locked, approved tenant Plan.');
            }
            // One Plan cannot have both a legacy execution and a canonical one.
            $legacy = $this->db->fetchOne(
                'SELECT run_id FROM cos_federation_runs
                 WHERE organization_id=:org AND plan_id=:plan',
                ['org'=>$org,'plan'=>$planId],
            );
            $canonical = $this->db->fetchOne(
                'SELECT run_id FROM cos_workflow_runs
                 WHERE organization_id=:org AND source_plan_id=:plan',
                ['org'=>$org,'plan'=>$planId],
            );
            if ($legacy !== false || $canonical !== false) {
                throw new DomainException('Plan already has an execution authority; duplicate dispatch is forbidden.');
            }
            $goal = $this->goals->specification($actor,(string)$plan['goal_id']);
            if ($goal === null || $goal->version !== (int)$plan['spec_version']
                || $goal->ownerId !== $actor->userId()->value()) {
                throw new DomainException('Canonical Goal owner or approved specification is stale.');
            }
            $this->approval->requireApproval($actor,$approvalId,$goal->goalId,$planId,
                $goal->version,(string)$plan['plan_json'],$goal->ownerId);
            $decoded = json_decode((string)$plan['plan_json'],true,512,JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !is_array($decoded['steps']??null)
                || !array_is_list($decoded['steps']) || $decoded['steps'] === []) {
                throw new DomainException('Approved Plan lacks a canonical executable graph.');
            }
            foreach ($decoded['steps'] as $step) {
                // First cutover slice deliberately allows approved external
                // Actions only. Non-action evidence needs its own claim path.
                if (!is_array($step) || ($step['side_effect_level']??null) !== 'external'
                    || !is_string($step['execution_binding']??null)
                    || !str_starts_with($step['execution_binding'],'action:')) {
                    throw new DomainException('Canonical rollout currently requires external Action-only Plans.');
                }
            }
            $definition = new WorkflowDefinitionSnapshot('federation.approved-plan','1.0.0',$decoded['steps']);
            $digest=hash('sha256',(string)$plan['plan_json']);
            $id=new WorkflowRunIdentity($org,$runId);
            $draft=new WorkflowRunDraft($id,$definition->id,$definition->version,$digest,
                $actor->userId()->value(),'federation:'.$runId,$planId,$approvalId,$digest,$definition);
            $this->workflow->create($draft,$actor);
            // Zero writes to cos_federation_runs/steps, no Action submission.
            return ['run_id'=>$runId,'state'=>'pending','execution_authority'=>'platform_workflow'];
        });
    }

    /** One operator-driven transition; no scheduler or background Action replay. */
    public function advance(TenantContext $actor, string $runId, string $approvalId): array
    {
        $this->authorized($actor);
        self::identifier($runId);
        $org=$actor->organizationId()->value();
        $id=new WorkflowRunIdentity($org,$runId);
        $run=$this->requireRun($id);
        $result=static fn(string $state,?string $step=null,?string $action=null): array => [
            'run_id'=>$runId,'state'=>$state,'step_id'=>$step,'action_id'=>$action,
            'execution_authority'=>'platform_workflow',
        ];
        if ($run->state === WorkflowStatus::COMPLETED) return $result('completed');
        if ($run->state !== WorkflowStatus::CREATED && $run->state !== WorkflowStatus::RUNNING) {
            return $result('manual_reconciliation_required');
        }
        if (!is_string($run->sourcePlanId) || $run->sourcePlanId === '') {
            throw new DomainException('Canonical Federation Run has no approved Plan.');
        }
        $row=$this->db->fetchAssociative(
            'SELECT plan_json,state FROM cos_federation_plans
             WHERE organization_id=:org AND plan_id=:plan',
            ['org'=>$org,'plan'=>$run->sourcePlanId],
        );
        if (!$row || $row['state'] !== 'approved'
            || $approvalId !== $this->db->fetchOne(
                'SELECT approval_reference FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run',
                ['org'=>$org,'run'=>$runId],
            )) {
            throw new DomainException('Canonical advance requires the original Plan Approval reference.');
        }
        $this->planEvidence->assertTrusted($id,$run->sourcePlanId,
            hash('sha256',(string)$row['plan_json']),$approvalId);

        $command=new WorkflowRunCommand($id,$actor,$run->revision,$run->correlationId);
        if ($run->state === WorkflowStatus::CREATED) {
            $this->workflow->compareAndTransition(new WorkflowRunTransition(
                $command,WorkflowStatus::CREATED,WorkflowStatus::RUNNING,'approved Goal run started'));
            return $result('running');
        }
        $steps=json_decode((string)$row['plan_json'],true,512,JSON_THROW_ON_ERROR)['steps']??null;
        if (!is_array($steps) || !array_is_list($steps)) throw new DomainException('Pinned Plan topology missing.');
        $selection=$this->scheduler->select(
            new WorkflowDefinitionSnapshot('federation.approved-plan','1.0.0',$steps),
            $this->workflow->steps($id),['external'],
        );
        // A prior COMPLETED flag is never enough to authorize a subsequent Action.
        foreach ($selection->completed as $stepId) {
            $completed=$this->findStep($id,$stepId);
            $receipt=$completed->resultReference;
            if ($receipt===null) return $result('manual_reconciliation_required',$stepId);
            try {
                $proof=$this->receipts->attestForAudit($id,$stepId,$receipt,
                    hash('sha256',(string)$row['plan_json']));
                if (!$proof->verified || $proof->evidenceReference !== $receipt) {
                    return $result('manual_reconciliation_required',$stepId);
                }
            } catch (\Throwable) {
                return $result('manual_reconciliation_required',$stepId);
            }
        }
        if ($selection->state === WorkflowStepSelectionState::COMPLETE) {
            $this->workflow->compareAndTransition(new WorkflowRunTransition(
                $command,WorkflowStatus::RUNNING,WorkflowStatus::COMPLETED,'all Action receipts attested'));
            return $result('completed');
        }
        if ($selection->state === WorkflowStepSelectionState::AMBIGUOUS) {
            return $result('manual_reconciliation_required',$selection->stepId);
        }
        $step=$this->findStep($id,(string)$selection->stepId);
        $key='wf:'.$step->idempotencyKey;
        $actions=$this->db->fetchAllAssociative(
            'SELECT id,status FROM cos_actions WHERE organization_id=:org AND idempotency_key=:key',
            ['org'=>$org,'key'=>$key],
        );
        if (count($actions)>1) return $result('manual_reconciliation_required',$step->stepId);
        $action=$actions[0]??null;
        if ($selection->state === WorkflowStepSelectionState::CLAIMED) {
            if ($action===null) return $result('manual_reconciliation_required',$step->stepId);
            if ($action['status'] === 'COMPLETED') {
                $claim=$this->db->fetchAssociative(
                    'SELECT claim_token,claim_expires_at FROM cos_workflow_steps
                     WHERE organization_id=:org AND run_id=:run AND step_id=:step AND state=:state',
                    ['org'=>$org,'run'=>$runId,'step'=>$step->stepId,'state'=>'claimed'],
                );
                if (!$claim || !is_string($claim['claim_token'])
                    || strtotime((string)$claim['claim_expires_at'].' UTC') <= time()) {
                    return $result('manual_reconciliation_required',$step->stepId,(string)$action['id']);
                }
                try {
                    $this->workflow->checkpoint(new WorkflowStepCheckpoint(
                        $command,$step->stepId,$claim['claim_token'],
                        StepStatus::COMPLETED,'action:'.$action['id']));
                    return $result('step_completed',$step->stepId,(string)$action['id']);
                } catch (\Throwable) {
                    return $result('manual_reconciliation_required',$step->stepId,(string)$action['id']);
                }
            }
            if (in_array($action['status'],['FAILED','REJECTED'],true)) {
                return $result('manual_reconciliation_required',$step->stepId,(string)$action['id']);
            }
            return $result('awaiting_action',$step->stepId,(string)$action['id']);
        }
        if ($selection->state !== WorkflowStepSelectionState::PENDING) {
            return $result('manual_reconciliation_required',$step->stepId);
        }
        // Existing pending Action after a crash is recovered by its stable key,
        // NEVER resubmitted. In particular QUEUED/RUNNING with no claim is unsafe.
        if ($action!==null && !in_array($action['status'],['PENDING_APPROVAL','QUEUED'],true)) {
            return $result('manual_reconciliation_required',$step->stepId,(string)$action['id']);
        }
        if ($action===null) {
            $proposal=$this->approvedProposal($actor,$id,$step->stepId,$steps,$step->idempotencyKey);
            $owner=$this->domains->ownerOfAction($proposal->type);
            if ($owner===null || !$this->modules->isEnabled($org,$owner)) {
                throw new DomainException('Canonical Action Domain owner is unavailable.');
            }
            $policy=$this->policyEngine->evaluate($proposal->type,
                $this->contexts->build($org,$proposal),
                $this->policies->activeFor($org,$proposal->type));
            if ($policy->decision !== PolicyDecision::ApprovalRequired) {
                throw new DomainException('Canonical Goal Action requires independent human Approval.');
            }
            $issued=$this->actions->submit($org,$proposal,$run->correlationId);
            $action=['id'=>$issued->id,'status'=>$issued->status->value];
            if ($issued->status !== ActionStatus::PendingApproval) {
                return $result('manual_reconciliation_required',$step->stepId,$issued->id);
            }
        }
        // Claim is the only Step writer and must be persisted before the Action
        // worker is allowed to execute. A crash remains reconciliable by Action ID.
        try {
            $this->workflow->claim(new WorkflowStepClaim(
                $command,$step->stepId,'goal-approval:'.$actor->userId()->value(),86400));
        } catch (\Throwable) {
            return $result('manual_reconciliation_required',$step->stepId,(string)$action['id']);
        }
        return $result('awaiting_human_approval',$step->stepId,(string)$action['id']);
    }

    private function approvedProposal(TenantContext $actor, WorkflowRunIdentity $id,
        string $stepId,array $steps,string $stepKey): ActionProposal
    {
        $step=null;
        foreach ($steps as $item) if (($item['id']??null)===$stepId) $step=$item;
        if (!is_array($step)) throw new DomainException('Workflow approved Action step missing.');
        $contract=$this->capabilities->describe((string)($step['capability_id']??''));
        $goal=$this->db->fetchAssociative(
            'SELECT g.owner_id,g.current_spec_version,p.spec_version
             FROM cos_workflow_runs r
             JOIN cos_federation_plans p ON p.organization_id=r.organization_id AND p.plan_id=r.source_plan_id
             JOIN cos_federation_goals g ON g.organization_id=p.organization_id AND g.goal_id=p.goal_id
             WHERE r.organization_id=:org AND r.run_id=:run AND p.state=:approved',
            ['org'=>$id->organizationId,'run'=>$id->runId,'approved'=>'approved'],
        );
        if (!$goal || $goal['owner_id']!==$actor->userId()->value()
            || (int)$goal['current_spec_version']!==(int)$goal['spec_version']
            || $contract===null || $contract->version!==($step['capability_version']??null)
            || $contract->ownerDomain!==($step['owner_domain']??null)
            || $contract->executionBinding!==($step['execution_binding']??null)
            || $contract->sideEffectLevel!=='external'
            || $contract->approvalPolicy!=='required' || $contract->idempotency!=='required'
            || !str_starts_with($contract->executionBinding,'action:')
            || !is_array($step['input']??null)) {
            throw new DomainException('Canonical Step binding or tenant owner is not approved.');
        }
        (new CapabilityJsonInputValidator())->validate($contract,$step['input']);
        return new ActionProposal(
            substr($contract->executionBinding,7),
            (string)$step['input']['target_type'],(string)$step['input']['target_id'],
            (array)$step['input']['parameters'],'USER',$actor->userId()->value(),
            'APPROVAL_REQUIRED','HIGH','wf:'.$stepKey,
            ['federation'=>['run_id'=>$id->runId,'step_id'=>$stepId]],
        );
    }

    private function requireRun(WorkflowRunIdentity $id): \Platform\Workflow\Model\WorkflowRunSnapshot
    {
        $run=$this->workflow->get($id);
        if ($run===null || $run->definitionId!=='federation.approved-plan') {
            throw new DomainException('Canonical Federation Run is not found.');
        }
        return $run;
    }

    private function findStep(WorkflowRunIdentity $id,string $stepId): \Platform\Workflow\Model\WorkflowStepSnapshot
    {
        foreach ($this->workflow->steps($id) as $step) {
            if ($step->stepId===$stepId) return $step;
        }
        throw new DomainException('Canonical Federation Step missing.');
    }

    private function authorized(TenantContext $actor): void
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)
            || !$this->modules->isEnabled($actor->organizationId()->value(),'federation')) {
            throw new DomainException('Canonical Goal execution requires enabled tenant module and manager.');
        }
    }

    private static function identifier(string $value): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/',$value)) {
            throw new DomainException('Invalid canonical Goal Run/Plan identifier.');
        }
    }
}
