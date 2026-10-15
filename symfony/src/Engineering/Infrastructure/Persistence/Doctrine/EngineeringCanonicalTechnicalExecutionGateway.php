<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Domain\Agent\EngineeringAgentTask;
use Doctrine\DBAL\Connection;
use DomainException;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowStepSnapshot;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;
use Platform\Workflow\Service\WorkflowStepScheduler;
use Platform\Workflow\Contract\WorkflowTechnicalExternalStoreInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/**
 * Canonical technical authority for explicitly allowlisted Engineering
 * AgentRuns. Legacy Engineering Workflow is still the BUSINESS state owner.
 *
 * Caller MUST hold the existing feature advisory lock. begin/complete/fail
 * run inside the same SQL transaction as the original AgentRun record.
 * No external retry, Action, PR merge, QA or human approval is authorized here.
 */
final readonly class EngineeringCanonicalTechnicalExecutionGateway
{
    public function __construct(private Connection $db, private WorkflowTechnicalExternalStoreInterface $technical) {}

    public function begin(string $agentRunId, string $workflowId, EngineeringAgentTask $task, string $traceId): void
    {
        $ctx=$this->db->fetchAssociative(
            'SELECT f.organization_id,w.trace_id AS workflow_trace,a.trace_id AS agent_trace,w.current_state
             FROM cos_engineering_features f
             JOIN cos_engineering_workflows w ON w.feature_id=f.id
             JOIN cos_engineering_agent_runs a
               ON a.feature_id=f.id AND a.workflow_execution_id=w.id
             WHERE f.id=:feature AND w.id=:workflow AND a.id=:agent
               AND a.idempotency_key=:key AND a.agent_role=:role AND a.status="RUNNING"',
            ['feature'=>$task->featureId,'workflow'=>$workflowId,'agent'=>$agentRunId,
             'key'=>$task->idempotencyKey,'role'=>$task->role->value],
        );
        if (!$ctx) throw new DomainException('Engineering canonical claim has no trusted AgentRun/Feature/Workflow provenance.');
        $org=(string)$ctx['organization_id'];
        $rollout=$this->db->fetchAssociative(
            'SELECT mode,approved_by,approved_at FROM cos_engineering_technical_rollouts
             WHERE organization_id=:org AND feature_id=:feature FOR UPDATE',
            ['org'=>$org,'feature'=>$task->featureId],
        );
        if (!$rollout || ($rollout['mode']??'off')==='off') return;
        if ($rollout['mode']!=='canonical' || trim((string)$rollout['approved_by'])===''
            || $rollout['approved_at']===null) {
            throw new DomainException('Engineering technical cutover is not explicitly authorized.');
        }
        if ($ctx['agent_trace']!==$traceId) {
            throw new DomainException('Engineering canonical claim correlation differs from persistent workflow.');
        }
        $blocked=$this->db->fetchOne(
            'SELECT agent_run_id FROM cos_engineering_technical_links
             WHERE organization_id=:org AND feature_id=:feature
               AND technical_state IN ("claimed","ambiguous")
             LIMIT 1 FOR UPDATE',
            ['org'=>$org,'feature'=>$task->featureId],
        );
        if ($blocked!==false) {
            throw new DomainException('Prior Engineering canonical claim is unresolved: reconcile before another external AgentRun.');
        }
        $canonicalRun='eng-agent:'.$agentRunId;
        $step=['id'=>'execute','capability_id'=>'engineering.agent.'.strtolower($task->role->value),
            'capability_version'=>'1.0.0','owner_domain'=>'platform.engineering',
            'execution_binding'=>'engineering:agent-run',
            'side_effect_level'=>'external','depends_on'=>[],
            'input'=>['feature_id'=>$task->featureId,'agent_role'=>$task->role->value,
                'idempotency_key'=>$task->idempotencyKey,
                'execution_task_id'=>$task->id]];
        $definition=new WorkflowDefinitionSnapshot('engineering.agent_execution','1.0.0',[$step]);
        // Reuse shared graph scheduler before minting a fenced technical lease.
        $selection=(new WorkflowStepScheduler())->select($definition,
            [new WorkflowStepSnapshot('execute',$step['capability_id'],'1.0.0',
                'external','pending')],['none','external']);
        if ($selection->stepId!=='execute' || $selection->state->value!=='pending') {
            throw new DomainException('Engineering technical Step is not DAG-admissible.');
        }
        $inputDigest=WorkflowDefinitionFingerprint::digest([[
            'feature'=>$task->featureId,'role'=>$task->role->value,
            'key'=>$task->idempotencyKey,'snapshot'=>$task->inputSnapshot,
        ]]);
        // Platform Workflow Store is the sole technical Run/Step writer.
        // Rollout and persisted AgentRun provenance were checked above.
        $token=$this->technical->beginGuardedExternal(
            new WorkflowRunIdentity($org,$canonicalRun),$definition,$inputDigest,
            'engineering:'.$task->role->value,$traceId,$agentRunId,7200,
        );
        $this->db->executeStatement(
            'INSERT INTO cos_engineering_technical_links
             (organization_id,agent_run_id,engineering_workflow_id,feature_id,
              canonical_run_id,agent_idempotency_key,claim_token,technical_state,created_at,updated_at)
             VALUES (:org,:agent,:workflow,:feature,:run,:key,:token,"claimed",UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',
            ['org'=>$org,'agent'=>$agentRunId,'workflow'=>$workflowId,
                'feature'=>$task->featureId,'run'=>$canonicalRun,
                'key'=>$task->idempotencyKey,'token'=>$token],
        );
    }

    /**
     * Finalize ONLY against already persisted completed legacy AgentRun.
     * Caller handles business artifact/reviewer/QA semantics separately.
     */
    public function complete(string $agentRunId): void
    {
        $link=$this->link($agentRunId);
        if (!$link) return; // Non-allowlisted legacy Run; no dual writer.
        if ($link['technical_state']!=='claimed') {
            throw new DomainException('Engineering canonical technical claim already finalized or ambiguous.');
        }
        $record=$this->db->fetchAssociative(
            'SELECT status,output,trace_id,feature_id,workflow_execution_id,idempotency_key
             FROM cos_engineering_agent_runs WHERE id=:agent',
            ['agent'=>$agentRunId],
        );
        if (!$record || $record['status']!=='COMPLETED' || $record['output']===null
            || $record['feature_id']!==$link['feature_id']
            || $record['workflow_execution_id']!==$link['engineering_workflow_id']
            || $record['idempotency_key']!==$link['agent_idempotency_key']) {
            throw new DomainException('Engineering canonical completion lacks persisted matching AgentRun output.');
        }
        $snapshot=json_decode((string)$record['output'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || $snapshot===[]) {
            throw new DomainException('Engineering canonical receipt cannot attest empty result.');
        }
        $digest=hash('sha256',json_encode($snapshot,
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $receipt='engineering:agent-run:completed:v1:'.$agentRunId.':'.$digest;
        $this->technical->completeGuardedExternal(
            new WorkflowRunIdentity((string)$link['organization_id'],(string)$link['canonical_run_id']),
            'execute',(string)$link['claim_token'],$receipt,(string)$record['trace_id'],
        );
        $changed=$this->db->executeStatement(
            'UPDATE cos_engineering_technical_links SET technical_state="completed",
              updated_at=UTC_TIMESTAMP(6)
             WHERE organization_id=:org AND agent_run_id=:agent
               AND claim_token=:token AND technical_state="claimed"',
            ['org'=>$link['organization_id'],'agent'=>$agentRunId,'token'=>$link['claim_token']],
        );
        if ($changed!==1) throw new DomainException('Canonical AgentRun link has stale claim fencing.');
    }

    public function quarantine(string $agentRunId): void
    {
        $link=$this->link($agentRunId);
        if (!$link || $link['technical_state']==='ambiguous') return;
        if ($link['technical_state']!=='claimed') return;
        $args=['org'=>$link['organization_id'],'run'=>$link['canonical_run_id'],
            'agent'=>$agentRunId,'token'=>$link['claim_token']];
        $this->technical->quarantineGuardedExternal(
            new WorkflowRunIdentity((string)$link['organization_id'],(string)$link['canonical_run_id']),
            'execute',(string)$link['claim_token'],
        );
        $this->db->executeStatement(
            'UPDATE cos_engineering_technical_links SET technical_state="ambiguous",
             updated_at=UTC_TIMESTAMP(6)
             WHERE organization_id=:org AND agent_run_id=:agent
               AND claim_token=:token AND technical_state="claimed"',$args,
        );
    }

    /** @return array<string,mixed>|null */
    private function link(string $agentRunId): ?array
    {
        // The actual tenant comes from Feature ownership, not caller-provided ID.
        $row=$this->db->fetchAssociative(
            'SELECT l.* FROM cos_engineering_technical_links l
             JOIN cos_engineering_features f
               ON f.id=l.feature_id AND f.organization_id=l.organization_id
             WHERE l.agent_run_id=:agent',
            ['agent'=>$agentRunId],
        );
        return $row?:null;
    }
}
