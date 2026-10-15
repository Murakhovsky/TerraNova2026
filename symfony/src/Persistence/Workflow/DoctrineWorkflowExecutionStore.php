<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Workflow\Model\StepStatus;
use Kernel\Workflow\Model\WorkflowStatus;
use LogicException;
use Platform\Workflow\Contract\WorkflowExecutionStoreInterface;
use Platform\Workflow\Contract\WorkflowEvidenceSignalStoreInterface;
use Platform\Workflow\Model\WorkflowEvidenceSignal;
use Platform\Workflow\Contract\WorkflowTechnicalExternalStoreInterface;
use Platform\Workflow\Contract\WorkflowTechnicalExternalEvidenceInterface;
use Platform\Workflow\Contract\WorkflowReceiptVerifierInterface;
use Platform\Workflow\Contract\WorkflowStepClaimAdmissionInterface;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowReceiptRequest;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowRunSnapshot;
use Platform\Workflow\Model\WorkflowRunTransition;
use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Model\WorkflowStepCheckpoint;
use Platform\Workflow\Model\WorkflowStepSnapshot;
use Platform\Workflow\Service\DurableWorkflowTransitionPolicy;
use Platform\Workflow\Service\WorkflowDependencyGraph;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;
use Platform\Workflow\Service\WorkflowStepScheduler;

/**
 * FIRST DURABLE FOUNDATION, not a production execution switch.
 * No Action dispatch, Agent execution, automatic recovery or dual writes.
 * A run row serializes every transition/claim/checkpoint for its tenant.
 */
final readonly class DoctrineWorkflowExecutionStore implements WorkflowExecutionStoreInterface, WorkflowTechnicalExternalStoreInterface, WorkflowEvidenceSignalStoreInterface
{
    public function __construct(
        private Connection $db,
        private WorkflowReceiptVerifierInterface $receipts,
        private WorkflowStepClaimAdmissionInterface $admission,
        private ?WorkflowTechnicalExternalEvidenceInterface $technicalEvidence = null,
    ) {}

    public function create(WorkflowRunDraft $draft, TenantContext $actor): WorkflowRunSnapshot
    {
        self::authorized($actor, $draft->identity);
        if ($draft->actorId !== $actor->userId()->value()) {
            throw new DomainException('Workflow creator and authenticated actor differ.');
        }
        if ($draft->sourcePlanId !== null && $draft->approvalReference === null) {
            throw new DomainException('Workflow Plan reference requires an approval reference.');
        }
        $definition = $draft->definition;
        if ($definition === null || $definition->steps === []) {
            throw new DomainException('Durable Run requires a pinned executable definition.');
        }
        $graph = (new WorkflowDependencyGraph())->resolve($definition->steps);
        foreach ($definition->steps as $step) {
            foreach (['capability_id','capability_version','side_effect_level'] as $field) {
                if (!is_string($step[$field] ?? null) || $step[$field] === '') {
                    throw new DomainException('Workflow definition lacks executable binding: ' . $field);
                }
            }
            if (!in_array($step['side_effect_level'], ['external','none'], true)) {
                throw new DomainException('Workflow definition has unsupported side-effect class.');
            }
        }
        // A pure dry-run selection detects inconsistent topology before persistence.
        if (count($graph) !== count($definition->steps)) {
            throw new DomainException('Workflow definition topology validation failed.');
        }
        $serialized = json_encode($definition->steps, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return $this->db->transactional(function () use ($draft, $serialized, $definition): WorkflowRunSnapshot {
            $id = $draft->identity;
            $now = self::now();
            $this->db->insert('cos_workflow_runs', [
                'organization_id' => $id->organizationId, 'run_id' => $id->runId,
                'definition_id' => $draft->definitionId, 'definition_version' => $draft->definitionVersion,
                'definition_json' => $serialized, 'definition_digest' => WorkflowDefinitionFingerprint::digest($definition->steps),
                'input_digest' => $draft->inputDigest, 'actor_id' => $draft->actorId,
                'correlation_id' => $draft->correlationId, 'state' => WorkflowStatus::CREATED->value,
                'revision' => 1, 'source_plan_id' => $draft->sourcePlanId,
                'source_plan_digest' => $draft->sourcePlanDigest, 'approval_reference' => $draft->approvalReference,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($definition->steps as $ordinal => $step) {
                $this->db->insert('cos_workflow_steps', [
                    'organization_id' => $id->organizationId, 'run_id' => $id->runId,
                    'step_id' => $step['id'], 'ordinal_no' => $ordinal,
                    'capability_id' => $step['capability_id'], 'capability_version' => $step['capability_version'],
                    'side_effect_level' => $step['side_effect_level'],
                    'state' => StepStatus::PENDING->value, 'revision' => 1, 'attempts' => 0,
                    'idempotency_key' => hash('sha256', $id->organizationId . "\0" . $id->runId . "\0" . $step['id']),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            return $this->mustGet($id);
        });
    }

    public function get(WorkflowRunIdentity $identity): ?WorkflowRunSnapshot
    {
        // Internal read API. External/controller callers must authorize the tenant first.
        $row = $this->db->fetchAssociative(
            'SELECT * FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run',
            self::keys($identity),
        );
        return $row ? self::runSnapshot($row) : null;
    }

    /** @return list<WorkflowStepSnapshot> */
    public function steps(WorkflowRunIdentity $identity): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM cos_workflow_steps WHERE organization_id=:org AND run_id=:run ORDER BY ordinal_no',
            self::keys($identity),
        );
        return array_map(static fn(array $row): WorkflowStepSnapshot => self::stepSnapshot($row), $rows);
    }

    public function compareAndTransition(WorkflowRunTransition $transition): WorkflowRunSnapshot
    {
        $command = $transition->command;
        self::authorized($command->actor, $command->identity);
        return $this->db->transactional(function () use ($transition, $command): WorkflowRunSnapshot {
            $run = $this->lockedRun($command->identity);
            self::assertRunRevision($run, $command->expectedRevision, $transition->expectedState);
            DurableWorkflowTransitionPolicy::assertTransition($transition->expectedState->value, $transition->nextState->value);
            if ($transition->nextState === WorkflowStatus::COMPLETED) {
                $rows = $this->lockedStepRows($command->identity);
                if ($rows === []) throw new DomainException('Empty Workflow cannot finalize.');
                foreach ($rows as $row) {
                    if ($row['state'] !== StepStatus::COMPLETED->value
                        || !is_string($row['result_reference']) || $row['result_reference'] === '') {
                        throw new DomainException('Workflow cannot finalize without completed verified steps.');
                    }
                    $this->assertReceipt($command->actor, $command->identity, $run, $row);
                }
            }
            $count = $this->db->executeStatement(
                'UPDATE cos_workflow_runs SET state=:next, revision=revision+1, updated_at=:now
                  WHERE organization_id=:org AND run_id=:run AND revision=:revision AND state=:expected',
                ['next' => $transition->nextState->value, 'now' => self::now(),
                    ...self::keys($command->identity), 'revision' => $command->expectedRevision,
                    'expected' => $transition->expectedState->value],
            );
            if ($count !== 1) throw new LogicException('Stale Workflow Run transition.');
            return $this->mustGet($command->identity);
        });
    }

    public function claim(WorkflowStepClaim $claim): WorkflowStepSnapshot
    {
        $command = $claim->command;
        self::authorized($command->actor, $command->identity);
        return $this->db->transactional(function () use ($claim, $command): WorkflowStepSnapshot {
            $run = $this->lockedRun($command->identity);
            self::assertRunRevision($run, $command->expectedRevision, WorkflowStatus::RUNNING);
            $rows = $this->lockedStepRows($command->identity);
            $plan = json_decode((string) $run['definition_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($plan) || !array_is_list($plan)
                || !hash_equals((string) $run['definition_digest'],
                    WorkflowDefinitionFingerprint::digest($plan))) {
                throw new DomainException('Pinned Workflow definition has been modified.');
            }
            $byId = [];
            foreach ($rows as $row) $byId[$row['step_id']] = $row;
            $selection = (new WorkflowStepScheduler())->select(
                new WorkflowDefinitionSnapshot((string) $run['definition_id'], (string) $run['definition_version'], $plan),
                array_map(static fn(array $row): WorkflowStepSnapshot => self::stepSnapshot($row), $rows),
                ['none','external'],
            );
            if ($selection->state->value !== 'pending' || $selection->stepId !== $claim->stepId) {
                throw new DomainException('Workflow claim bypasses the immutable dependency order or active claim.');
            }
            $row = $byId[$claim->stepId];
            // Production's composite admission explicitly guards BOTH Action
            // and evidence-only claims. Historical isolated Store callers using
            // a custom admission retain their narrow fixture semantics.
            if ($row['side_effect_level'] === 'external'
                || ($row['side_effect_level'] === 'none'
                    && $this->admission instanceof WorkflowClaimAdmissionRouter)) {
                $this->admission->assertAuthorized($claim, self::stepSnapshot($row));
            }
            $token = bin2hex(random_bytes(16));
            $changed = $this->db->executeStatement(
                'UPDATE cos_workflow_steps SET state=:next, attempts=attempts+1, revision=revision+1,
                        claim_token=:token, claimed_by=:worker,
                        claim_expires_at=DATE_ADD(NOW(6), INTERVAL :lease SECOND), updated_at=NOW(6)
                  WHERE organization_id=:org AND run_id=:run AND step_id=:step AND state=:pending AND revision=:step_revision',
                ['next' => StepStatus::CLAIMED->value, 'token' => $token,
                    'worker' => $claim->workerId, 'lease' => $claim->leaseSeconds,
                    ...self::keys($command->identity), 'step' => $claim->stepId,
                    'pending' => StepStatus::PENDING->value, 'step_revision' => (int) $row['revision']],
            );
            if ($changed !== 1) throw new LogicException('Stale Workflow Step claim.');
            $this->advanceRunRevision($command->identity, $command->expectedRevision);
            $fresh = $this->db->fetchAssociative(
                'SELECT * FROM cos_workflow_steps WHERE organization_id=:org AND run_id=:run AND step_id=:step',
                [...self::keys($command->identity), 'step' => $claim->stepId],
            );
            if (!$fresh) throw new LogicException('Claimed Step disappeared.');
            return self::stepSnapshot($fresh, true);
        });
    }

    public function checkpoint(WorkflowStepCheckpoint $checkpoint): WorkflowStepSnapshot
    {
        $command = $checkpoint->command;
        self::authorized($command->actor, $command->identity);
        return $this->db->transactional(function () use ($checkpoint, $command): WorkflowStepSnapshot {
            $run = $this->lockedRun($command->identity);
            self::assertRunRevision($run, $command->expectedRevision, WorkflowStatus::RUNNING);
            $row = $this->db->fetchAssociative(
                'SELECT * FROM cos_workflow_steps WHERE organization_id=:org AND run_id=:run AND step_id=:step FOR UPDATE',
                [...self::keys($command->identity), 'step' => $checkpoint->stepId],
            );
            if (!$row || $row['state'] !== StepStatus::CLAIMED->value
                || !is_string($row['claim_token'])
                || !hash_equals($row['claim_token'], $checkpoint->claimToken)) {
                throw new DomainException('Invalid or stale Workflow Step claim token.');
            }
            if ($row['claim_expires_at'] === null
                || strtotime((string) $row['claim_expires_at'] . ' UTC') <= time()) {
                throw new DomainException('Expired Workflow claim requires reconciliation, not automatic completion.');
            }
            DurableWorkflowTransitionPolicy::assertTransition(
                StepStatus::CLAIMED->value, $checkpoint->nextState->value, true);
            if ($checkpoint->nextState === StepStatus::WAITING
                && ($row['side_effect_level'] !== 'none'
                    || $checkpoint->resultReference !== null)) {
                throw new DomainException('Only a read-only Step may wait for independent Domain evidence.');
            }
            if ($checkpoint->nextState === StepStatus::COMPLETED) {
                if ($checkpoint->resultReference === null || $checkpoint->resultReference === '') {
                    throw new DomainException('Completed Workflow Step must provide an independently attested receipt.');
                }
                if ($row['side_effect_level'] === 'none') {
                    // Verify the independently persisted fact against the
                    // exact checkpoint time, not the old claim timestamp.
                    // Both timestamp update and receipt attestation happen
                    // inside ONE transaction: failure rolls back the timestamp,
                    // retains the claimed Step and cannot invent completion.
                    $changed=$this->db->executeStatement(
                        'UPDATE cos_workflow_steps SET updated_at=NOW(6)
                         WHERE organization_id=:org AND run_id=:run AND step_id=:step
                           AND state=:state AND claim_token=:token',
                        [...self::keys($command->identity),'step'=>$checkpoint->stepId,
                            'state'=>StepStatus::CLAIMED->value,'token'=>$checkpoint->claimToken],
                    );
                    if ($changed!==1) throw new DomainException('Stale evidence-only Step claim.');
                }
                $this->assertReceipt(
                    $command->actor, $command->identity, $run,
                    ['step_id' => $checkpoint->stepId, 'result_reference' => $checkpoint->resultReference],
                );
            }
            $changed = $this->db->executeStatement(
                'UPDATE cos_workflow_steps SET state=:next, revision=revision+1,
                    result_reference=:receipt, claim_token=NULL, claimed_by=NULL,
                    claim_expires_at=NULL, updated_at=NOW(6)
                  WHERE organization_id=:org AND run_id=:run AND step_id=:step
                    AND state=:claimed AND claim_token=:token AND revision=:step_revision',
                ['next' => $checkpoint->nextState->value, 'receipt' => $checkpoint->resultReference,
                    ...self::keys($command->identity), 'step' => $checkpoint->stepId,
                    'claimed' => StepStatus::CLAIMED->value, 'token' => $checkpoint->claimToken,
                    'step_revision' => (int) $row['revision']],
            );
            if ($changed !== 1) throw new LogicException('Stale Workflow checkpoint.');
            $this->advanceRunRevision($command->identity, $command->expectedRevision);
            $fresh = $this->db->fetchAssociative(
                'SELECT * FROM cos_workflow_steps WHERE organization_id=:org AND run_id=:run AND step_id=:step',
                [...self::keys($command->identity), 'step' => $checkpoint->stepId],
            );
            if (!$fresh) throw new LogicException('Checkpointed Workflow Step disappeared.');
            return self::stepSnapshot($fresh);
        });
    }


    /**
     * Complete a deliberately WAITING read-only Step using independent,
     * already persisted Domain evidence. No worker replay, no arbitrary
     * Signal payload, no Action/Tool/Agent invocation.
     *
     * Run and Step are locked together. Timestamp update and receipt proof
     * share one transaction so an absent/tampered Domain fact rolls back.
     */
    public function completeWaitingEvidence(WorkflowEvidenceSignal $signal): WorkflowStepSnapshot
    {
        $command = $signal->command;
        self::authorized($command->actor, $command->identity);
        return $this->db->transactional(function () use ($signal, $command): WorkflowStepSnapshot {
            $run = $this->lockedRun($command->identity);
            self::assertRunRevision($run,$command->expectedRevision,WorkflowStatus::RUNNING);
            if ($run['definition_id'] !== 'federation.approved-plan'
                || !is_string($run['source_plan_id'])
                || !is_string($run['approval_reference'])
                || !is_string($run['source_plan_digest'])) {
                throw new DomainException('Wait/Signal requires an approved canonical Goal Run.');
            }
            $graph = json_decode((string) $run['definition_json'],true,512,JSON_THROW_ON_ERROR);
            if (!is_array($graph) || !array_is_list($graph)
                || !hash_equals((string)$run['definition_digest'],
                    WorkflowDefinitionFingerprint::digest($graph))) {
                throw new DomainException('Wait/Signal definition digest is untrusted.');
            }
            $row = $this->db->fetchAssociative(
                'SELECT * FROM cos_workflow_steps
                 WHERE organization_id=:org AND run_id=:run AND step_id=:step FOR UPDATE',
                [...self::keys($command->identity),'step'=>$signal->stepId],
            );
            $matches=array_values(array_filter($graph,static fn($part):bool=>
                is_array($part) && ($part['id']??null)===$signal->stepId));
            $pinned=$matches[0]??null;
            if (!$row || $row['state']!==StepStatus::WAITING->value
                || $row['side_effect_level']!=='none'
                || (int)$row['revision']!==$signal->expectedStepRevision
                || (int)$row['attempts']!==1
                || $row['claim_token']!==null
                || $row['result_reference']!==null
                || count($matches)!==1 || !is_array($pinned)
                || ($pinned['side_effect_level']??null)!=='none'
                || ($pinned['capability_id']??null)!==$row['capability_id']
                || ($pinned['capability_version']??null)!==$row['capability_version']
                || !is_string($pinned['execution_binding']??null)
                || !str_starts_with($pinned['execution_binding'],'read:')
                || ($pinned['evidence']['reference']??null)!==$signal->resultReference
                || ($pinned['evidence']['run_id']??null)!==$command->identity->runId
                || ($pinned['evidence']['input_digest']??null)!==$run['input_digest']
                || $command->correlationId!==$run['correlation_id']
                || $command->actor->userId()->value()!==$run['actor_id']) {
                throw new DomainException('Wait/Signal is not a trusted, tenant-owned read-only checkpoint.');
            }
            // Independent verifiers that compare event times to Step.updated_at
            // must see the exact candidate checkpoint instant before proof.
            $updated=$this->db->executeStatement(
                'UPDATE cos_workflow_steps SET updated_at=NOW(6)
                 WHERE organization_id=:org AND run_id=:run AND step_id=:step
                   AND state=:state AND revision=:revision',
                [...self::keys($command->identity),'step'=>$signal->stepId,
                    'state'=>StepStatus::WAITING->value,'revision'=>$signal->expectedStepRevision],
            );
            if ($updated!==1) throw new LogicException('Waiting evidence candidate lost CAS.');
            $this->assertReceipt($command->actor,$command->identity,$run,[
                'step_id'=>$signal->stepId,'result_reference'=>$signal->resultReference,
            ]);
            $updated=$this->db->executeStatement(
                'UPDATE cos_workflow_steps
                 SET state=:completed,revision=revision+1,result_reference=:receipt,
                     updated_at=NOW(6)
                 WHERE organization_id=:org AND run_id=:run AND step_id=:step
                   AND state=:waiting AND revision=:revision',
                ['completed'=>StepStatus::COMPLETED->value,
                    'receipt'=>$signal->resultReference,...self::keys($command->identity),
                    'step'=>$signal->stepId,'waiting'=>StepStatus::WAITING->value,
                    'revision'=>$signal->expectedStepRevision],
            );
            if ($updated!==1) throw new LogicException('Waiting evidence Step CAS lost.');
            $this->advanceRunRevision($command->identity,$command->expectedRevision);
            $fresh=$this->db->fetchAssociative(
                'SELECT * FROM cos_workflow_steps
                 WHERE organization_id=:org AND run_id=:run AND step_id=:step',
                [...self::keys($command->identity),'step'=>$signal->stepId],
            );
            if (!$fresh) throw new LogicException('Completed Signal Step disappeared.');
            return self::stepSnapshot($fresh);
        });
    }

    /**
     * Trusted, narrow technical Agent admission. Domain owns AgentRun proof
     * and human-approved rollout; this Store owns every Run/Step mutation.
     * Kept distinct from human-approved Action claim admission on purpose.
     */
    public function beginGuardedExternal(
        WorkflowRunIdentity $identity,
        WorkflowDefinitionSnapshot $definition,
        string $inputDigest,
        string $actorId,
        string $correlationId,
        string $workerId,
        int $leaseSeconds
    ): string {
        if (!str_starts_with($identity->runId, 'eng-agent:')
            || $definition->id !== 'engineering.agent_execution'
            || $definition->version !== '1.0.0'
            || count($definition->steps) !== 1
            || !preg_match('/^[a-f0-9]{64}$/D', $inputDigest)
            || !str_starts_with($actorId, 'engineering:')
            || $correlationId === '' || $workerId === ''
            || $leaseSeconds < 1 || $leaseSeconds > 7200) {
            throw new DomainException('Untrusted technical Agent invocation cannot create a canonical Workflow.');
        }
        $step=$definition->steps[0];
        if (($step['id']??null)!=='execute'
            || ($step['execution_binding']??null)!=='engineering:agent-run'
            || ($step['side_effect_level']??null)!=='external'
            || ($step['depends_on']??null)!==[]
            || !is_string($step['capability_id']??null)
            || !str_starts_with($step['capability_id'],'engineering.agent.')
            || ($step['capability_version']??null)!=='1.0.0'
            || ($step['input']['idempotency_key']??null)===''
            || ($step['input']['execution_task_id']??null)===''
            || ($step['input']['feature_id']??null)==='') {
            throw new DomainException('Technical Agent graph is not a pinned Engineering execution.');
        }
        $selected=(new WorkflowStepScheduler())->select($definition,
            [new WorkflowStepSnapshot('execute',$step['capability_id'],'1.0.0','external','pending')],
            ['none','external']);
        if ($selected->stepId!=='execute' || $selected->state->value!=='pending') {
            throw new DomainException('Technical Agent graph cannot be claimed.');
        }
        $stepKey=hash('sha256',$identity->organizationId."\0".$identity->runId."\0execute");
        $token=bin2hex(random_bytes(16));
        $this->db->transactional(function () use (
            $identity,$definition,$inputDigest,$actorId,$correlationId,
            $workerId,$leaseSeconds,$step,$stepKey,$token
        ):void {
            if ($this->technicalEvidence===null) {
                throw new DomainException('Unconfigured technical Agent evidence: fail closed.');
            }
            $this->technicalEvidence->assertBegin(
                $identity,$definition,$inputDigest,$actorId,$correlationId,$workerId,
            );
            $this->db->executeStatement(
                'INSERT INTO cos_workflow_runs
                 (organization_id,run_id,definition_id,definition_version,
                  definition_json,definition_digest,input_digest,actor_id,
                  correlation_id,state,revision,created_at,updated_at)
                 VALUES (:org,:run,:definition_id,:version,
                         :definition,:fingerprint,:input,:actor,:trace,"running",2,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',
                ['org'=>$identity->organizationId,'run'=>$identity->runId,
                 'definition_id'=>$definition->id,'version'=>$definition->version,
                 'definition'=>json_encode($definition->steps,JSON_THROW_ON_ERROR),
                 'fingerprint'=>WorkflowDefinitionFingerprint::digest($definition->steps),
                 'input'=>$inputDigest,'actor'=>$actorId,'trace'=>$correlationId],
            );
            $this->db->executeStatement(
                'INSERT INTO cos_workflow_steps
                 (organization_id,run_id,step_id,ordinal_no,capability_id,capability_version,
                  side_effect_level,state,revision,attempts,idempotency_key,claim_token,
                  claimed_by,claim_expires_at,created_at,updated_at)
                 VALUES (:org,:run,"execute",0,:capability,"1.0.0",
                         "external","claimed",2,1,:stepkey,:token,:worker,
                         DATE_ADD(UTC_TIMESTAMP(6),INTERVAL :lease SECOND),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',
                ['org'=>$identity->organizationId,'run'=>$identity->runId,
                 'capability'=>$step['capability_id'],'stepkey'=>$stepKey,
                 'token'=>$token,'worker'=>$workerId,'lease'=>$leaseSeconds],
            );
        });
        return $token;
    }

    /**
     * Receipt was independently derived from the persisted Domain AgentRun
     * by the caller. Store checks the immutable technical graph and lease.
     */
    public function completeGuardedExternal(
        WorkflowRunIdentity $identity,
        string $stepId,
        string $claimToken,
        string $receipt,
        string $correlationId
    ): void {
        if ($stepId!=='execute' || !preg_match('/^[a-f0-9]{32}$/D',$claimToken)
            || !str_starts_with($identity->runId,'eng-agent:')
            || !str_starts_with($receipt,'engineering:agent-run:completed:v1:')
            || $correlationId==='') {
            throw new DomainException('Invalid canonical technical Agent completion.');
        }
        $this->db->transactional(function () use ($identity,$stepId,$claimToken,$receipt,$correlationId):void {
            $run=$this->lockedRun($identity);
            $definition=json_decode((string)$run['definition_json'],true,512,JSON_THROW_ON_ERROR);
            if ($run['definition_id']!=='engineering.agent_execution'
                || $run['state']!=='running' || (int)$run['revision']!==2
                || $run['correlation_id']!==$correlationId
                || !preg_match('/^[a-f0-9]{64}$/D',(string)$run['input_digest'])
                || !is_array($definition) || count($definition)!==1
                || !hash_equals((string)$run['definition_digest'],WorkflowDefinitionFingerprint::digest($definition))
                || ($definition[0]['execution_binding']??null)!=='engineering:agent-run') {
                throw new DomainException('Technical Agent immutable Workflow diverged before checkpoint.');
            }
            if ($this->technicalEvidence===null) {
                throw new DomainException('Unconfigured technical Agent evidence: fail closed.');
            }
            $this->technicalEvidence->assertCompletion(
                $identity,$stepId,$claimToken,$receipt,$correlationId,
            );
            $changed=$this->db->executeStatement(
                'UPDATE cos_workflow_steps SET state="completed",revision=revision+1,
                 result_reference=:receipt,claim_expires_at=NULL,updated_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND run_id=:run AND step_id=:step
                   AND state="claimed" AND attempts=1 AND revision=2
                   AND claim_token=:token AND claim_expires_at>UTC_TIMESTAMP(6)',
                ['org'=>$identity->organizationId,'run'=>$identity->runId,'step'=>$stepId,
                 'receipt'=>$receipt,'token'=>$claimToken],
            );
            if ($changed!==1) throw new DomainException('Stale technical Agent lease: reconcile external effect.');
            $changed=$this->db->executeStatement(
                'UPDATE cos_workflow_runs SET state="completed",revision=revision+1,
                 checkpoint_reference=:receipt,updated_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND run_id=:run AND state="running" AND revision=2',
                ['org'=>$identity->organizationId,'run'=>$identity->runId,'receipt'=>$receipt],
            );
            if ($changed!==1) throw new DomainException('Technical Agent completion failed canonical CAS.');
        });
    }

    public function quarantineGuardedExternal(
        WorkflowRunIdentity $identity,
        string $stepId,
        string $claimToken
    ): void {
        if ($stepId!=='execute' || !preg_match('/^[a-f0-9]{32}$/D',$claimToken)
            || !str_starts_with($identity->runId,'eng-agent:')) {
            throw new DomainException('Invalid canonical technical Agent quarantine.');
        }
        $this->db->transactional(function () use ($identity,$stepId,$claimToken):void {
            $run=$this->lockedRun($identity);
            if ($run['definition_id']!=='engineering.agent_execution'
                || $run['state']!=='running' || (int)$run['revision']!==2) {
                throw new DomainException('Technical Agent is not in an unresolved running claim.');
            }
            if ($this->technicalEvidence===null) {
                throw new DomainException('Unconfigured technical Agent evidence: fail closed.');
            }
            $this->technicalEvidence->assertQuarantine($identity,$stepId,$claimToken);
            $changed=$this->db->executeStatement(
                'UPDATE cos_workflow_steps SET state="ambiguous",revision=revision+1,
                 updated_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND run_id=:run AND step_id=:step
                   AND state="claimed" AND claim_token=:token',
                ['org'=>$identity->organizationId,'run'=>$identity->runId,
                 'step'=>$stepId,'token'=>$claimToken],
            );
            if ($changed!==1) throw new DomainException('Technical Agent quarantine has stale claim fencing.');
            $changed=$this->db->executeStatement(
                'UPDATE cos_workflow_runs SET state="ambiguous",revision=revision+1,
                 updated_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND run_id=:run AND state="running" AND revision=2',
                ['org'=>$identity->organizationId,'run'=>$identity->runId],
            );
            if ($changed!==1) throw new DomainException('Technical Agent quarantine failed canonical CAS.');
        });
    }

    /** @param array<string,mixed> $row */
    private function assertReceipt(TenantContext $actor, WorkflowRunIdentity $identity, array $run, array $row): void
    {
        $reference = $row['result_reference'] ?? null;
        if (!is_string($reference) || $reference === '') {
            throw new DomainException('Workflow receipt reference is absent.');
        }
        $proof = $this->receipts->attest(new WorkflowReceiptRequest(
            $identity, $actor, (string) $row['step_id'], $reference, (string) $run['input_digest'],
        ));
        if (!$proof->verified || $proof->identity != $identity
            || $proof->stepId !== $row['step_id'] || $proof->evidenceReference === null) {
            throw new DomainException('Workflow receipt could not be independently verified.');
        }
    }

    /** @return array<string,mixed> */
    private function lockedRun(WorkflowRunIdentity $identity): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run FOR UPDATE',
            self::keys($identity),
        );
        if (!$row) throw new DomainException('Workflow Run not found in tenant.');
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function lockedStepRows(WorkflowRunIdentity $identity): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM cos_workflow_steps WHERE organization_id=:org AND run_id=:run ORDER BY ordinal_no FOR UPDATE',
            self::keys($identity),
        );
    }

    /** @param array<string,mixed> $row */
    private static function assertRunRevision(array $row, int $revision, WorkflowStatus $status): void
    {
        if ((int) $row['revision'] !== $revision || $row['state'] !== $status->value) {
            throw new LogicException('Stale Workflow Run revision or incompatible state.');
        }
    }

    private function advanceRunRevision(WorkflowRunIdentity $identity, int $revision): void
    {
        $changed = $this->db->executeStatement(
            'UPDATE cos_workflow_runs SET revision=revision+1, updated_at=NOW(6)
              WHERE organization_id=:org AND run_id=:run AND revision=:revision AND state=:state',
            [...self::keys($identity), 'revision' => $revision, 'state' => WorkflowStatus::RUNNING->value],
        );
        if ($changed !== 1) throw new LogicException('Workflow Run claim/checkpoint revision collision.');
    }

    private function mustGet(WorkflowRunIdentity $identity): WorkflowRunSnapshot
    {
        return $this->get($identity) ?? throw new LogicException('Persisted Workflow Run missing.');
    }

    /** @param array<string,mixed> $row */
    private static function runSnapshot(array $row): WorkflowRunSnapshot
    {
        return new WorkflowRunSnapshot(
            new WorkflowRunIdentity((string) $row['organization_id'], (string) $row['run_id']),
            (string) $row['definition_id'], (string) $row['definition_version'],
            WorkflowStatus::from((string) $row['state']), (int) $row['revision'],
            (string) $row['correlation_id'],
            $row['source_plan_id'] === null ? null : (string) $row['source_plan_id'],
            $row['checkpoint_reference'] === null ? null : (string) $row['checkpoint_reference'],
            $row['source_plan_digest'] === null ? null : (string) $row['source_plan_digest'],
        );
    }

    /** @param array<string,mixed> $row */
    private static function stepSnapshot(array $row, bool $withClaimToken = false): WorkflowStepSnapshot
    {
        return new WorkflowStepSnapshot(
            (string) $row['step_id'], (string) $row['capability_id'], (string) $row['capability_version'],
            (string) $row['side_effect_level'], (string) $row['state'],
            (int) $row['attempts'], (int) $row['revision'],
            $row['result_reference'] === null ? null : (string) $row['result_reference'],
            $withClaimToken && $row['claim_token'] !== null ? (string) $row['claim_token'] : null,
            (string) $row['idempotency_key'],
        );
    }

    private static function authorized(TenantContext $actor, WorkflowRunIdentity $identity): void
    {
        if ($actor->organizationId()->value() !== $identity->organizationId
            || !$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Durable Workflow mutation requires authorized tenant manager.');
        }
    }

    /** @return array{org:string,run:string} */
    private static function keys(WorkflowRunIdentity $identity): array
    {
        return ['org' => $identity->organizationId, 'run' => $identity->runId];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
