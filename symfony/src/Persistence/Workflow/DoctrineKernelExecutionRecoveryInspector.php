<?php
declare(strict_types=1);
namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Contract\WorkflowKernelExecutionEvidenceInterface;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;
use Throwable;

/**
 * Bounded read-only reconciliation queue. In-flight/expired/ambiguous claims
 * NEVER get requeued; only an independent native source receipt can verify
 * already-terminal results.
 */
final readonly class DoctrineKernelExecutionRecoveryInspector
{
    public function __construct(
        private Connection $db,
        private WorkflowKernelExecutionEvidenceInterface $native,
    ) {}

    /** @return array<string,mixed> */
    public function inspectTenant(string $organizationId,int $limit=100,?string $runId=null):array
    {
        if(trim($organizationId)==='' || strlen($organizationId)>190) {
            throw new \DomainException('Recovery inspection requires a bounded tenant.');
        }
        if($runId!==null && (trim($runId)==='' || strlen($runId)>190)) {
            throw new \DomainException('Kernel recovery Run filter must be bounded and non-empty.');
        }
        $limit=max(1,min(100,$limit));
        $runFilter=$runId!==null?' AND r.run_id=:target':'';
        $parameters=['org'=>$organizationId];
        if($runId!==null)$parameters['target']=$runId;
        $rows=$this->db->fetchAllAssociative(
            'SELECT r.run_id,r.definition_id,r.definition_json,r.definition_digest,
                    r.input_digest,r.correlation_id,r.state AS run_state,r.revision AS run_revision,
                    r.checkpoint_reference,s.step_id,s.capability_id,s.state AS step_state,
                    s.side_effect_level,s.revision AS step_revision,s.attempts,
                    s.claim_expires_at,s.result_reference
             FROM cos_workflow_runs r
             LEFT JOIN cos_workflow_steps s ON s.organization_id=r.organization_id
               AND s.run_id=r.run_id
             WHERE r.organization_id=:org
               AND r.definition_id IN ("kernel.agent_execution","kernel.tool_execution")'
             .$runFilter.'
             ORDER BY r.updated_at DESC,r.run_id,s.step_id LIMIT '.($limit+1),
            $parameters,
        );
        $truncated=count($rows)>$limit;
        $rows=array_slice($rows,0,$limit);
        $items=[];$counts=['verified'=>0,'in_flight'=>0,'manual_required'=>0,'drift'=>0];
        foreach($rows as $r) {
            $kind=$r['definition_id']==='kernel.agent_execution'?'agent':'tool';
            $identity=new WorkflowRunIdentity($organizationId,(string)$r['run_id']);
            $disposition='drift';$reason='invalid_immutable_graph';
            try {
                $graph=json_decode((string)$r['definition_json'],true,512,JSON_THROW_ON_ERROR);
                $step=is_array($graph) && count($graph)===1?$graph[0]:null;
                if(!is_array($step) || ($step['id']??null)!=='execute'
                    || ($step['execution_binding']??null)!=='kernel:'.$kind
                    || ($step['capability_id']??null)!==$r['capability_id']
                    || ($r['step_id']??null)!=='execute'
                    || (string)$r['definition_digest']!==WorkflowDefinitionFingerprint::digest($graph)
                    || !is_string($step['source_id']??null)) {
                    throw new \DomainException('Graph/Step identity mismatch.');
                }
                if($r['step_state']==='completed' && $r['run_state']==='completed'
                    && is_string($r['result_reference']) && $r['result_reference']!==''
                    && $r['checkpoint_reference']===$r['result_reference']) {
                    $this->native->assertCompletion(
                        $identity,$kind,$step['source_id'],(string)$r['capability_id'],
                        (string)$r['input_digest'],(string)$r['result_reference'],
                        (string)$r['correlation_id'],
                    );
                    $disposition='verified';$reason='independent_native_receipt';
                } elseif($r['step_state']==='claimed' && $r['run_state']==='running'
                    && $r['claim_expires_at']!==null
                    && strtotime((string)$r['claim_expires_at'].' UTC')>time()) {
                    $disposition='in_flight';$reason='leased_technical_execution';
                } elseif(in_array($r['step_state'],['claimed','ambiguous'],true)
                    || in_array($r['run_state'],['running','ambiguous'],true)) {
                    $disposition='manual_required';$reason='uncertain_or_expired_claim_no_auto_replay';
                } else {
                    $disposition='drift';$reason='unsupported_kernel_terminal_state';
                }
            } catch(Throwable) {
                $disposition='drift';$reason='native_receipt_or_graph_untrusted';
            }
            ++$counts[$disposition];
            $items[]=['run_id'=>$r['run_id'],'kind'=>$kind,
                'run_state'=>$r['run_state'],'step_state'=>$r['step_state'],
                'run_revision'=>(int)$r['run_revision'],
                'step_revision'=>$r['step_revision']===null?null:(int)$r['step_revision'],
                'disposition'=>$disposition,'reason'=>$reason];
        }
        return [
            'source'=>'kernel_canonical_execution_recovery_v1','organization_id'=>$organizationId,
            'status'=>$truncated?'incomplete':(
                $counts['drift']>0||$counts['manual_required']>0?'manual_reconciliation_required':
                'technical_read_only_consistent'),
            'coverage_complete'=>!$truncated,
            'counts'=>$counts,'runs'=>$items,
            'read_only'=>true,'automatic_replay_authorized'=>false,
            'business_outcome_verified'=>false,'release_authorized'=>false,
        ];
    }
}
