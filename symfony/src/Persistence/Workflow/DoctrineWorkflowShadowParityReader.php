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
     * Operator-facing, production-schema fleet audit. No projections are
     * synthesized, no rollout is switched and no cutover is authorized.
     *
     * @return array<string,mixed>
     */
    public function engineeringFleet(string $organizationId, int $limit = 50): array
    {
        if (trim($organizationId)==='') {
            throw new DomainException('Engineering shadow fleet requires a tenant.');
        }
        $limit=max(1,min(100,$limit));
        $features=$this->db->fetchAllAssociative(
            'SELECT o.feature_id,o.mode,o.approved_by,o.approved_at,
                    f.status AS feature_status
             FROM cos_engineering_technical_rollouts o
             JOIN cos_engineering_features f ON f.id=o.feature_id
               AND f.organization_id=o.organization_id
             WHERE o.organization_id=:org
             ORDER BY o.feature_id LIMIT '.$limit,
            ['org'=>$organizationId],
        );
        $total=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM cos_engineering_technical_rollouts o
             JOIN cos_engineering_features f ON f.id=o.feature_id
                AND f.organization_id=o.organization_id
             WHERE o.organization_id=:org',
            ['org'=>$organizationId],
        );
        $counts=['match'=>0,'partial'=>0,'drift'=>0,'no_target'=>0];
        $reports=[];
        $historyTruncated=false;
        foreach($features as $feature) {
            // Analyze all known technical workflows, not only the latest
            // successful one. A previously unreconciled/legacy execution
            // MUST remain visible to the migration decision.
            $ids=$this->db->fetchFirstColumn(
                'SELECT w.id FROM cos_engineering_workflows w
                 WHERE w.feature_id=:feature
                 ORDER BY w.started_at DESC,w.id DESC LIMIT 101',
                ['feature'=>$feature['feature_id']],
            );
            $truncated=count($ids)>100;
            $historyTruncated=$historyTruncated || $truncated;
            $workflows=[];
            $technical=['match'=>0,'partial'=>0,'drift'=>0,'no_target'=>0];
            $agentCounts=['match'=>0,'partial'=>0,'drift'=>0,'no_target'=>0];
            foreach(array_slice($ids,0,100) as $id) {
                $report=$this->engineeringWorkflow($organizationId,(string)$id);
                foreach(($report['technical_counts']??[]) as $key=>$count) {
                    if (array_key_exists($key,$agentCounts)) $agentCounts[$key]+=(int)$count;
                }
                $workflows[]=[
                    'workflow_id'=>$id,
                    'business_stage'=>$report['business_stage']??null,
                    'status'=>$report['status'],
                    'technical_counts'=>$report['technical_counts']??null,
                ];
                $state=(string)$report['status'];
                if (array_key_exists($state,$technical)) ++$technical[$state];
                else ++$technical['drift'];
            }
            $status=$technical['drift']>0?'drift':
                ($technical['no_target']>0 || $ids===[] || $truncated?'no_target':'partial');
            // Removing a permit while a technical claim exists must be
            // surfaced as drift rather than silently returning MATCH.
            $hasLinked=($technical['partial']+$technical['match']+$technical['drift'])>0;
            $permit=$feature['mode']==='canonical'
                && trim((string)$feature['approved_by'])!==''
                && $feature['approved_at']!==null;
            if ($hasLinked && !$permit) $status='drift';
            ++$counts[$status];
            $reports[]=[
                'feature_id'=>$feature['feature_id'],
                'rollout_mode'=>$feature['mode'],
                'operator_approved'=>$permit,
                'feature_status'=>$feature['feature_status'],
                'workflow_id'=>$ids[0]??null,
                'workflows'=>$workflows,
                'history_truncated'=>$truncated,
                'workflow_counts'=>$technical,
                'technical_counts'=>$agentCounts,
                'status'=>$status,
                'business_gate_authority'=>'legacy_engineering',
                'cutover_authorized'=>false,
            ];
        }
        $incomplete=$historyTruncated || $total>count($features);
        $status=$counts['drift']>0?'drift':
            ($incomplete || $counts['no_target']>0 || $reports===[]?'no_target':'partial');
        return [
            'source'=>'engineering_fleet','organization_id'=>$organizationId,
            'status'=>$status,'counts'=>$counts,'features'=>$reports,
            'total_rollout_features'=>$total,'evaluated_features'=>count($features),
            'history_truncated'=>$historyTruncated,'coverage_complete'=>!$incomplete,
            'read_only'=>true,'cutover_authorized'=>false,
            'business_gate_authority'=>'legacy_engineering',
        ];
    }

    /**
     * Fleet-facing read-only technical parity for the entire Engineering
     * workflow. Never equate AgentRun parity with business-stage/QA approval.
     *
     * @return array<string,mixed>
     */
    public function engineeringWorkflow(string $organizationId, string $workflowId): array
    {
        if ($organizationId==='' || $workflowId==='') {
            throw new DomainException('Engineering shadow requires scoped workflow and tenant.');
        }
        $workflow=$this->db->fetchAssociative(
            'SELECT w.id,w.feature_id,w.current_state,w.status,w.version,
                    w.trace_id,w.resume_state
             FROM cos_engineering_workflows w
             JOIN cos_engineering_features f ON f.id=w.feature_id
             WHERE f.organization_id=:org AND w.id=:workflow',
            ['org'=>$organizationId,'workflow'=>$workflowId],
        );
        if (!$workflow) return [
            'source'=>'engineering_workflow','workflow_id'=>$workflowId,
            'status'=>'no_target','reason'=>'legacy_engineering_workflow_not_found',
            'read_only'=>true,'cutover_authorized'=>false,
        ];
        $ids=$this->db->fetchFirstColumn(
            'SELECT id FROM cos_engineering_agent_runs
             WHERE feature_id=:feature AND workflow_execution_id=:workflow
             ORDER BY started_at,id',
            ['feature'=>$workflow['feature_id'],'workflow'=>$workflowId],
        );
        $runs=[];$counts=['match'=>0,'partial'=>0,'drift'=>0,'no_target'=>0];
        foreach($ids as $id) {
            $report=$this->engineeringAgent($organizationId,(string)$id);
            $counts[$report->status->value]++;
            $runs[]=['agent_run_id'=>$id,'status'=>$report->status->value,
                'differences'=>$report->differences];
        }
        $overall=$counts['drift']>0?'drift':
            ($counts['no_target']>0 || $runs===[]?'no_target':'partial');
        return [
            'source'=>'engineering_workflow','workflow_id'=>$workflowId,
            'feature_id'=>$workflow['feature_id'],
            'business_stage'=>$workflow['current_state'],
            'business_status'=>$workflow['status'],
            'business_version'=>(int)$workflow['version'],
            'business_gate_authority'=>'legacy_engineering',
            'technical_counts'=>$counts,'technical_runs'=>$runs,
            'status'=>$overall,'read_only'=>true,
            'cutover_authorized'=>false,
        ];
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
        $executionTaskId=$sourceInput['_execution_task_id']??null;
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
            || ($definition['input']['idempotency_key']??null)!==$legacy['idempotency_key']
            || !is_string($executionTaskId)
            || ($definition['input']['execution_task_id']??null)!==$executionTaskId) {
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
        $lease=$this->db->fetchAssociative(
            'SELECT state,revision,claim_token,claimed_by,
                    (claim_expires_at>UTC_TIMESTAMP(6)) AS lease_active
             FROM cos_workflow_steps
             WHERE organization_id=:org AND run_id=:run AND step_id="execute"',
            ['org'=>$organizationId,'run'=>$identity->runId],
        );
        if (!$lease || $lease['claim_token']!==$link['claim_token']
            || $lease['claimed_by']!==$agentRunId) {
            $drift[]='engineering_fenced_claim_identity_mismatch';
        }
        if ($legacy['status']==='RUNNING') {
            if ($canonical->status->value!=='running' || $step->state!=='claimed'
                || $link['technical_state']!=='claimed'
                || (int)($lease['lease_active']??0)!==1
                || (int)($lease['revision']??0)!==2) {
                $drift[]='agent_running_claim_mismatch';
            }
        } elseif ($legacy['status']==='COMPLETED') {
            $output=json_decode((string)$legacy['output'],true,512,JSON_THROW_ON_ERROR);
            $hash=hash('sha256',json_encode($output,
                JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $expected='engineering:agent-run:completed:v1:'.$agentRunId.':'.$hash;
            if ($canonical->status->value!=='completed' || $step->state!=='completed'
                || $link['technical_state']!=='completed'
                || !hash_equals($expected,(string)$step->resultReference)) {
                $drift[]='agent_completed_receipt_mismatch';
            }
        } elseif ($legacy['status']==='FAILED') {
            if ($canonical->status->value!=='ambiguous' || $step->state!=='ambiguous'
                || $link['technical_state']!=='ambiguous') {
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
