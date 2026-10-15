<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use DomainException;
use Platform\Workflow\Contract\WorkflowTechnicalExternalEvidenceInterface;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;

/**
 * Domain-owned, read-only AgentRun provenance for the Platform Store.
 * A direct Store caller cannot fabricate a human-permitted Agent execution.
 * All assertions run inside the Store's canonical transaction.
 */
final readonly class EngineeringCanonicalTechnicalExecutionEvidence implements WorkflowTechnicalExternalEvidenceInterface
{
    public function __construct(private Connection $db) {}

    public function assertBegin(
        WorkflowRunIdentity $identity,
        WorkflowDefinitionSnapshot $definition,
        string $inputDigest,
        string $actorId,
        string $correlationId,
        string $workerId
    ): void {
        if ($identity->runId !== 'eng-agent:'.$workerId || count($definition->steps)!==1) {
            throw new DomainException('Technical Agent identity is not tied to the source AgentRun.');
        }
        $row=$this->db->fetchAssociative(
            'SELECT a.id,a.agent_role,a.idempotency_key,a.trace_id,a.status,a.input_snapshot,
                    a.feature_id,w.status AS workflow_status,w.current_agent_run_id,
                    f.organization_id,o.mode,o.approved_by,o.approved_at
             FROM cos_engineering_agent_runs a
             JOIN cos_engineering_features f ON f.id=a.feature_id
             JOIN cos_engineering_workflows w
               ON w.id=a.workflow_execution_id AND w.feature_id=a.feature_id
             JOIN cos_engineering_technical_rollouts o
               ON o.organization_id=f.organization_id AND o.feature_id=f.id
             WHERE a.id=:agent AND f.organization_id=:org FOR UPDATE',
            ['agent'=>$workerId,'org'=>$identity->organizationId],
        );
        if (!$row || $row['status']!=='RUNNING'
            || $row['workflow_status']!=='RUNNING'
            || $row['current_agent_run_id']!==$workerId
            || $row['mode']!=='canonical'
            || trim((string)$row['approved_by'])==='' || $row['approved_at']===null
            || $row['trace_id']!==$correlationId
            || $actorId!=='engineering:'.$row['agent_role']) {
            throw new DomainException('Canonical technical claim lacks trusted live tenant AgentRun and operator rollout.');
        }
        $original=json_decode((string)$row['input_snapshot'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($original) || !is_string($original['_execution_task_id']??null)
            || $original['_execution_task_id']==='') {
            throw new DomainException('Engineering technical claim has no persisted execution task ID.');
        }
        $taskId=$original['_execution_task_id'];
        unset($original['_execution_task_id']);
        $expected=WorkflowDefinitionFingerprint::digest([[
            'feature'=>$row['feature_id'],'role'=>$row['agent_role'],
            'key'=>$row['idempotency_key'],'snapshot'=>$original,
        ]]);
        $step=$definition->steps[0];
        if (!hash_equals($expected,$inputDigest)
            || ($step['capability_id']??null)!=='engineering.agent.'.strtolower((string)$row['agent_role'])
            || ($step['input']['feature_id']??null)!==$row['feature_id']
            || ($step['input']['agent_role']??null)!==$row['agent_role']
            || ($step['input']['idempotency_key']??null)!==$row['idempotency_key']
            || ($step['input']['execution_task_id']??null)!==$taskId) {
            throw new DomainException('Technical Workflow definition differs from the actual persisted AgentRun.');
        }
        $unresolved=$this->db->fetchOne(
            'SELECT agent_run_id FROM cos_engineering_technical_links
             WHERE organization_id=:org AND feature_id=:feature
               AND technical_state IN ("claimed","ambiguous") LIMIT 1 FOR UPDATE',
            ['org'=>$identity->organizationId,'feature'=>$row['feature_id']],
        );
        if ($unresolved!==false) {
            throw new DomainException('Canonical technical execution blocked by unresolved earlier side effect.');
        }
    }

    public function assertCompletion(
        WorkflowRunIdentity $identity,
        string $stepId,
        string $claimToken,
        string $receipt,
        string $correlationId
    ): void {
        $row=$this->linkedAgent($identity,$stepId,$claimToken);
        if ($row['agent_status']!=='COMPLETED'
            || $row['finished_at']===null
            || $row['agent_trace']!==$correlationId
            || $row['output']===null) {
            throw new DomainException('Technical Workflow completion has no persisted completed AgentRun.');
        }
        $output=json_decode((string)$row['output'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($output) || $output===[]) {
            throw new DomainException('Technical Agent completion source output cannot be empty.');
        }
        $digest=hash('sha256',json_encode($output,JSON_THROW_ON_ERROR |
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $expected='engineering:agent-run:completed:v1:'.$row['agent_run_id'].':'.$digest;
        if (!hash_equals($expected,$receipt)) {
            throw new DomainException('Technical Workflow receipt does not attest the native AgentRun output.');
        }
    }

    public function assertQuarantine(
        WorkflowRunIdentity $identity,
        string $stepId,
        string $claimToken
    ): void {
        $this->linkedAgent($identity,$stepId,$claimToken);
    }

    /** @return array<string,mixed> */
    private function linkedAgent(WorkflowRunIdentity $identity,string $stepId,string $token): array
    {
        if ($stepId!=='execute') throw new DomainException('Invalid Agent technical step.');
        $row=$this->db->fetchAssociative(
            'SELECT l.agent_run_id,l.feature_id,l.agent_idempotency_key,
                    a.status AS agent_status,a.trace_id AS agent_trace,
                    a.idempotency_key AS current_key,a.output,a.finished_at
             FROM cos_engineering_technical_links l
             JOIN cos_engineering_features f
               ON f.id=l.feature_id AND f.organization_id=l.organization_id
             JOIN cos_engineering_agent_runs a
               ON a.id=l.agent_run_id AND a.feature_id=l.feature_id
             WHERE l.organization_id=:org AND l.canonical_run_id=:run
               AND l.claim_token=:token AND l.technical_state="claimed" FOR UPDATE',
            ['org'=>$identity->organizationId,'run'=>$identity->runId,'token'=>$token],
        );
        if (!$row || $identity->runId!=='eng-agent:'.$row['agent_run_id']
            || $row['agent_idempotency_key']!==$row['current_key']) {
            throw new DomainException('Technical Agent receipt lacks a matching source and fenced link.');
        }
        return $row;
    }
}
