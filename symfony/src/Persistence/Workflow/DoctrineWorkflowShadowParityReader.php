<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Platform\Workflow\Contract\WorkflowNonActionReceiptEvidenceInterface;
use Platform\Workflow\Model\WorkflowParityReport;
use Platform\Workflow\Model\WorkflowParityStatus;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Service\WorkflowShadowParityComparator;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;

/**
 * Read-only production-schema shadow comparator. This does not create a
 * canonical Run, synthesize a receipt or silently reconcile missing records.
 * Restrict direct callers to trusted operator CLI, not an HTTP endpoint.
 */
final readonly class DoctrineWorkflowShadowParityReader
{
    public function __construct(
        private Connection $db,
        private DoctrineWorkflowExecutionStore $canonical,
        private WorkflowShadowParityComparator $comparator,
        private FederationWorkflowPlanApprovalEvidence $plans,
        private DoctrineWorkflowActionReceiptVerifier $actions,
        private WorkflowNonActionReceiptEvidenceInterface $nonActions,
    ) {}

    public function federation(string $organizationId, string $legacyRunId): WorkflowParityReport
    {
        $id = new WorkflowRunIdentity($organizationId, $legacyRunId);
        $row = $this->db->fetchAssociative(
            'SELECT * FROM cos_federation_runs
             WHERE organization_id=:org AND run_id=:run',
            ['org'=>$organizationId,'run'=>$legacyRunId],
        );
        if (!$row) {
            return new WorkflowParityReport('federation',$legacyRunId,
                WorkflowParityStatus::NO_TARGET,['legacy_run_not_found']);
        }
        $row['steps']=$this->db->fetchAllAssociative(
            'SELECT step_id,capability_id,capability_version,side_effect_level,
                    state,attempts,idempotency_key,result_reference
             FROM cos_federation_steps
             WHERE organization_id=:org AND run_id=:run ORDER BY step_id',
            ['org'=>$organizationId,'run'=>$legacyRunId],
        );
        $target=$this->canonical->get($id);
        if ($target === null) {
            return $this->comparator->federation($row,null,[]);
        }
        $steps=$this->canonical->steps($id);
        $run=$this->db->fetchAssociative(
            'SELECT definition_json,input_digest,approval_reference,source_plan_id,source_plan_digest,
                    definition_digest
             FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run',
            ['org'=>$organizationId,'run'=>$legacyRunId],
        );
        $attestedDigest=null;
        $trusted=[];
        if ($run && $run['source_plan_id'] === $row['plan_id']
            && is_string($run['source_plan_digest'])
            && is_string($run['approval_reference'])) {
            try {
                $this->plans->assertTrusted($id,(string)$run['source_plan_id'],
                    (string)$run['source_plan_digest'],(string)$run['approval_reference']);
                $attestedDigest=(string)$run['source_plan_digest'];
            } catch (\Throwable) {
                // Distinguish untrusted from successful shadow parity.
            }
        }
        if ($run && $attestedDigest !== null) {
            $graph=json_decode((string)$run['definition_json'],true,512,JSON_THROW_ON_ERROR);
            if (is_array($graph) && array_is_list($graph)) {
                $byId=[];
                foreach ($graph as $step) {
                    if (is_array($step) && is_string($step['id']??null)) {
                        $byId[$step['id']]=$step;
                    }
                }
                foreach ($steps as $step) {
                    if ($step->state !== 'completed'
                        || !is_string($step->resultReference) || $step->resultReference==='') continue;
                    try {
                        if ($step->sideEffectLevel==='external') {
                            $proof=$this->actions->attestForAudit($id,$step->stepId,
                                $step->resultReference,(string)$run['input_digest']);
                            if (!$proof->verified || $proof->stepId!==$step->stepId
                                || $proof->evidenceReference!==$step->resultReference) continue;
                        } elseif ($step->sideEffectLevel==='none') {
                            if (!isset($byId[$step->stepId])) continue;
                            $this->nonActions->assertVerified($id,$step->stepId,
                                $step->resultReference,(string)$run['input_digest'],
                                $byId[$step->stepId]);
                        } else continue;
                        $trusted[$step->stepId]=$step->resultReference;
                    } catch (\Throwable) {
                        // An unverified receipt MUST NOT be interpreted as a MATCH.
                    }
                }
            }
        }
        return $this->comparator->federation($row,$target,$steps,$attestedDigest,$trusted);
    }

    /**
     * Technical AgentRun parity: each migrated AgentRun is one canonical
     * technical Run with a single fenced Step. Business stage and human
     * approvals remain with the original Engineering domain.
     */
    public function engineeringAgent(string $organizationId, string $agentRunId): WorkflowParityReport
    {
        if ($organizationId==='' || $agentRunId==='') {
            throw new DomainException('Engineering shadow comparison requires scoped IDs.');
        }
        $legacy=$this->db->fetchAssociative(
            'SELECT a.id,a.feature_id,a.workflow_execution_id,a.agent_role,
                    a.status,a.output,a.trace_id,a.idempotency_key,a.input_snapshot,
                    a.logical_attempt,a.technical_retry,a.finished_at,w.current_state,
                    w.resume_state,w.version AS workflow_version,w.status AS workflow_status,
                    f.organization_id
             FROM cos_engineering_agent_runs a
             JOIN cos_engineering_workflows w
               ON w.id=a.workflow_execution_id AND w.feature_id=a.feature_id
             JOIN cos_engineering_features f ON f.id=a.feature_id
             WHERE f.organization_id=:org AND a.id=:run',
            ['org'=>$organizationId,'run'=>$agentRunId],
        );
        if (!$legacy) {
            return new WorkflowParityReport('engineering_agent',$agentRunId,
                WorkflowParityStatus::NO_TARGET,['legacy_agent_run_not_found']);
        }
        $link=$this->db->fetchAssociative(
            'SELECT * FROM cos_engineering_technical_links
             WHERE organization_id=:org AND agent_run_id=:run',
            ['org'=>$organizationId,'run'=>$agentRunId],
        );
        if (!$link) {
            return new WorkflowParityReport('engineering_agent',$agentRunId,
                WorkflowParityStatus::NO_TARGET,['canonical_agent_run_not_linked']);
        }
        $identity=new WorkflowRunIdentity($organizationId,(string)$link['canonical_run_id']);
        $canonical=$this->canonical->get($identity);
        $steps=$this->canonical->steps($identity);
        $persisted=$this->db->fetchAssociative(
            'SELECT definition_json,definition_digest,input_digest,actor_id
             FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run',
            ['org'=>$organizationId,'run'=>$identity->runId],
        );
        $definition=null;
        if ($persisted) {
            $decoded=json_decode((string)$persisted['definition_json'],true,512,JSON_THROW_ON_ERROR);
            if (is_array($decoded) && array_is_list($decoded) && count($decoded)===1
                && is_array($decoded[0])) $definition=$decoded[0];
        }
        $sourceInput=json_decode((string)$legacy['input_snapshot'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($sourceInput)) $sourceInput=[];
        unset($sourceInput['_execution_task_id']);
        $expectedInput=WorkflowDefinitionFingerprint::digest([[
            'feature'=>$legacy['feature_id'],'role'=>$legacy['agent_role'],
            'key'=>$legacy['idempotency_key'],'snapshot'=>$sourceInput,
        ]]);
        $drift=[];
        if (!$persisted || !$definition || !hash_equals(
                (string)$persisted['definition_digest'],
                WorkflowDefinitionFingerprint::digest([$definition]))
            || $persisted['input_digest']!==$expectedInput
            || $persisted['actor_id']!=='engineering:'.$legacy['agent_role']
            || ($definition['id']??null)!=='execute'
            || ($definition['capability_id']??null)!==
                'engineering.agent.'.strtolower((string)$legacy['agent_role'])
            || ($definition['side_effect_level']??null)!=='external'
            || ($definition['execution_binding']??null)!=='engineering:agent-run'
            || ($definition['input']['feature_id']??null)!==$legacy['feature_id']
            || ($definition['input']['idempotency_key']??null)!==$legacy['idempotency_key']) {
            $drift[]='engineering_immutable_definition_or_input_mismatch';
        }
        if (!$canonical || count($steps)!==1) {
            $drift[]='canonical_technical_run_or_step_missing';
        } elseif ($canonical->definitionId!=='engineering.agent_execution'
            || $canonical->correlationId!==$legacy['trace_id']
            || $link['engineering_workflow_id']!==$legacy['workflow_execution_id']
            || $link['feature_id']!==$legacy['feature_id']
            || $link['agent_idempotency_key']!==$legacy['idempotency_key']
            || $steps[0]->stepId!=='execute'
            || $steps[0]->sideEffectLevel!=='external'
            || $steps[0]->attempts!==1
            || $steps[0]->idempotencyKey!==hash('sha256',
                $organizationId."\0".$identity->runId."\0execute")) {
            $drift[]='engineering_technical_claim_or_link_mismatch';
        }
        if ($drift!==[]) {
            return new WorkflowParityReport('engineering_agent',$agentRunId,
                WorkflowParityStatus::DRIFT,$drift);
        }
        $step=$steps[0];
        if ($legacy['status']==='RUNNING') {
            if ($canonical->status->value!=='running' || $step->state!=='claimed') {
                $drift[]='agent_running_claim_mismatch';
            }
        } elseif ($legacy['status']==='COMPLETED') {
            $output=json_decode((string)$legacy['output'],true,512,JSON_THROW_ON_ERROR);
            $hash=hash('sha256',json_encode($output,
                JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $expected='engineering:agent-run:completed:v1:'.$agentRunId.':'.$hash;
            if ($canonical->status->value!=='completed' || $step->state!=='completed'
                || !hash_equals($expected,(string)$step->resultReference)) {
                $drift[]='agent_completed_receipt_mismatch';
            }
        } elseif ($legacy['status']==='FAILED') {
            if ($canonical->status->value!=='ambiguous' || $step->state!=='ambiguous') {
                $drift[]='agent_failed_ambiguous_reconciliation_mismatch';
            }
        } else {
            $drift[]='unsupported_engineering_agent_status';
        }
        // A failed AgentRun with possible external side-effects can never be
        // declared fully matched until a separate recovery proof exists.
        $missing=$legacy['status']==='FAILED'
            ? ['ambiguous_external_effect_requires_manual_reconciliation'] : [];
        return new WorkflowParityReport('engineering_agent',$agentRunId,
            $drift!==[]?WorkflowParityStatus::DRIFT:
                ($missing!==[]?WorkflowParityStatus::PARTIAL:WorkflowParityStatus::MATCH),
            [...$drift,...$missing]);
    }
}
