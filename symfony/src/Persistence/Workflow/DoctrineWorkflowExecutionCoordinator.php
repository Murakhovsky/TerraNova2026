<?php
declare(strict_types=1);
namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Workflow\Model\WorkflowStatus;
use Platform\Workflow\Contract\WorkflowExecutionCoordinatorInterface;
use Platform\Workflow\Contract\WorkflowExecutionStoreInterface;
use Platform\Workflow\Contract\WorkflowReceiptVerifierInterface;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowReceiptRequest;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunSnapshot;
use Platform\Workflow\Model\WorkflowRunTransition;
use Platform\Workflow\Model\WorkflowStepSelectionState;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;
use Platform\Workflow\Service\WorkflowStepScheduler;

/** One technical control plane, without another side-effect dispatcher. */
final readonly class DoctrineWorkflowExecutionCoordinator implements WorkflowExecutionCoordinatorInterface
{
    public function __construct(
        private Connection $db,
        private WorkflowExecutionStoreInterface $store,
        private WorkflowStepScheduler $scheduler,
        private FederationWorkflowPlanApprovalEvidence $approval,
        private WorkflowReceiptVerifierInterface $receipts,
    ) {}

    public function start(WorkflowRunDraft $draft,TenantContext $actor):WorkflowRunSnapshot
    {
        $this->authorize($actor,$draft->identity->organizationId);
        if($draft->definitionId!=='federation.approved-plan' || $draft->definition===null
            || !is_string($draft->sourcePlanId) || !is_string($draft->sourcePlanDigest)
            || !is_string($draft->approvalReference)
            || $draft->actorId!==$actor->userId()->value()) {
            throw new DomainException('Approved canonical Goal required.');
        }
        $this->bindings($draft->definition);
        return $this->db->transactional(function () use ($draft,$actor):WorkflowRunSnapshot {
            $created=$this->store->create($draft,$actor);
            $this->approval->assertTrusted($draft->identity,$draft->sourcePlanId,
                $draft->sourcePlanDigest,$draft->approvalReference);
            return $created;
        });
    }

    public function advance(WorkflowRunCommand $command):WorkflowRunSnapshot
    {
        [$run,$row,$graph]=$this->verifiedRun($command);
        if($run->status===WorkflowStatus::CREATED) {
            return $this->transition($command,WorkflowStatus::CREATED,WorkflowStatus::RUNNING);
        }
        if($run->status!==WorkflowStatus::RUNNING) return $run;
        $decision=$this->scheduler->select(new WorkflowDefinitionSnapshot(
            (string)$row['definition_id'],(string)$row['definition_version'],$graph),
            $this->store->steps($command->identity),['external','none']);
        // Never mint work here. Action/Agent/Tool remain with their domain runtime.
        if($decision->state!==WorkflowStepSelectionState::COMPLETE) return $run;
        return $this->transition($command,WorkflowStatus::RUNNING,WorkflowStatus::COMPLETED);
    }

    public function resume(WorkflowRunCommand $command):WorkflowRunSnapshot
    {
        [$run,$row]=$this->verifiedRun($command);
        if($run->status!==WorkflowStatus::WAITING
            || !is_string($command->signalReference) || $command->signalReference==='') {
            throw new DomainException('Run resume needs authenticated, persisted Domain signal.');
        }
        $steps=$this->store->steps($command->identity);
        foreach($steps as $step) {
            if(in_array($step->state,['claimed','waiting','ambiguous','failed'],true)) {
                throw new DomainException('Unresolved Workflow Step prevents resume.');
            }
        }
        $proofs=array_values(array_filter($steps,static fn($step):bool=>
            $step->sideEffectLevel==='none' && $step->state==='completed'
                && $step->resultReference===$command->signalReference));
        if(count($proofs)!==1) throw new DomainException('Signal has no unique Domain receipt.');
        $proof=$this->receipts->attest(new WorkflowReceiptRequest($command->identity,
            $command->actor,$proofs[0]->stepId,$command->signalReference,(string)$row['input_digest']));
        if(!$proof->verified || $proof->identity!=$command->identity
            || $proof->stepId!==$proofs[0]->stepId
            || $proof->evidenceReference!==$command->signalReference) {
            throw new DomainException('Domain signal was not independently re-attested.');
        }
        return $this->transition($command,WorkflowStatus::WAITING,WorkflowStatus::RUNNING);
    }

    public function cancel(WorkflowRunCommand $command):WorkflowRunSnapshot
    {
        [$run]=$this->verifiedRun($command);
        if(!in_array($run->status,[WorkflowStatus::CREATED,WorkflowStatus::RUNNING,
            WorkflowStatus::WAITING],true)) {
            throw new DomainException('Uncertain or terminal execution cannot be cancelled.');
        }
        foreach($this->store->steps($command->identity) as $step) {
            if(in_array($step->state,['claimed','ambiguous','failed'],true)
                || ($step->sideEffectLevel==='external' && $step->state==='waiting')) {
                throw new DomainException('An in-flight Step requires reconciliation.');
            }
            if($step->sideEffectLevel==='external' && $step->state!=='completed') {
                $count=(int)$this->db->fetchOne(
                    'SELECT COUNT(*) FROM cos_actions
                     WHERE organization_id=:org AND idempotency_key IN (:canonical,:legacy)',
                    ['org'=>$command->identity->organizationId,
                     'canonical'=>'wf:'.$step->idempotencyKey,
                     'legacy'=>'fed:'.$step->idempotencyKey]);
                if($count!==0) throw new DomainException('Unfinished Action requires reconciliation.');
            }
        }
        return $this->transition($command,$run->status,WorkflowStatus::CANCELLED);
    }

    private function transition(WorkflowRunCommand $command,WorkflowStatus $from,
        WorkflowStatus $to):WorkflowRunSnapshot
    {
        return $this->store->compareAndTransition(new WorkflowRunTransition(
            $command,$from,$to,'operator authorized shared Workflow control plane'));
    }

    /** @return array{0:WorkflowRunSnapshot,1:array<string,mixed>,2:list<array<string,mixed>>} */
    private function verifiedRun(WorkflowRunCommand $command):array
    {
        $id=$command->identity;
        $this->authorize($command->actor,$id->organizationId);
        $run=$this->store->get($id);
        $row=$this->db->fetchAssociative(
            'SELECT definition_id,definition_version,definition_json,definition_digest,
                    input_digest,source_plan_id,source_plan_digest,approval_reference,
                    actor_id,correlation_id,revision,state
             FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run',
            ['org'=>$id->organizationId,'run'=>$id->runId]);
        if(!$run || !$row || $row['definition_id']!=='federation.approved-plan'
            || $row['actor_id']!==$command->actor->userId()->value()
            || $row['correlation_id']!==$command->correlationId
            || (int)$row['revision']!==$command->expectedRevision
            || $run->revision!==$command->expectedRevision
            || $row['state']!==$run->status->value
            || !is_string($row['source_plan_id'])
            || !is_string($row['source_plan_digest'])
            || !is_string($row['approval_reference'])) {
            throw new DomainException('Workflow Run ownership or revision drift.');
        }
        $graph=json_decode((string)$row['definition_json'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($graph)||!array_is_list($graph)||$graph===[]
            || WorkflowDefinitionFingerprint::digest($graph)!==$row['definition_digest']) {
            throw new DomainException('Pinned Workflow graph changed.');
        }
        $this->bindings(new WorkflowDefinitionSnapshot((string)$row['definition_id'],
            (string)$row['definition_version'],$graph));
        $this->approval->assertTrusted($id,(string)$row['source_plan_id'],
            (string)$row['source_plan_digest'],(string)$row['approval_reference']);
        return [$run,$row,$graph];
    }

    private function bindings(WorkflowDefinitionSnapshot $definition):void
    {
        foreach($definition->steps as $step) {
            if(!is_array($step)) throw new DomainException('Invalid Workflow Step.');
            $side=$step['side_effect_level']??null;
            $binding=$step['execution_binding']??null;
            if(!is_string($binding)
                || !(($side==='external' && str_starts_with($binding,'action:'))
                   || ($side==='none' && str_starts_with($binding,'read:')))) {
                throw new DomainException('Unknown Agent/Tool/Action/Wait capability has no approved runtime.');
            }
        }
    }

    private function authorize(TenantContext $actor,string $org):void
    {
        if($actor->organizationId()->value()!==$org
            || !$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Only authenticated tenant manager controls a Workflow.');
        }
    }
}
