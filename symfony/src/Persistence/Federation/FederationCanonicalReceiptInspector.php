<?php
declare(strict_types=1);
namespace App\Persistence\Federation;

use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use App\Persistence\Workflow\FederationWorkflowPlanApprovalEvidence;
use Doctrine\DBAL\Connection;
use Platform\Workflow\Contract\WorkflowNonActionReceiptEvidenceInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;
use Throwable;

/** Read-only, native-receipt-based attestation of every canonical Federation Run. */
final readonly class FederationCanonicalReceiptInspector
{
    public function __construct(
        private Connection $db,
        private FederationWorkflowPlanApprovalEvidence $plans,
        private DoctrineWorkflowActionReceiptVerifier $actions,
        private WorkflowNonActionReceiptEvidenceInterface $nonActions,
    ) {}

    /** @return array{status:string,reason:string,verified_steps:int,total_steps:int} */
    public function inspect(string $org,string $runId):array
    {
        $result=static fn(string $state,string $reason,int $done,int $total):array=>[
            'status'=>$state,'reason'=>$reason,'verified_steps'=>$done,'total_steps'=>$total,
        ];
        $run=$this->db->fetchAssociative(
            'SELECT state,definition_json,definition_digest,input_digest,source_plan_id,
                    source_plan_digest,approval_reference
             FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run
               AND definition_id="federation.approved-plan"',
            ['org'=>$org,'run'=>$runId],
        );
        if(!$run)return $result('drift','run_missing',0,0);
        $steps=$this->db->fetchAllAssociative(
            'SELECT step_id,state,capability_id,capability_version,side_effect_level,
                    attempts,result_reference FROM cos_workflow_steps
             WHERE organization_id=:org AND run_id=:run ORDER BY ordinal_no,step_id',
            ['org'=>$org,'run'=>$runId],
        );
        $verified=0;$unfinished=0;$total=count($steps);
        try {
            $identity=new WorkflowRunIdentity($org,$runId);
            $graph=json_decode((string)$run['definition_json'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($graph)||!array_is_list($graph)||$graph===[]||$total!==count($graph)
               || WorkflowDefinitionFingerprint::digest($graph)!==$run['definition_digest']){
                return $result('drift','graph_identity_mismatch',0,$total);
            }
            $this->plans->assertTrusted(
                $identity,(string)$run['source_plan_id'],
                (string)$run['source_plan_digest'],(string)$run['approval_reference'],
            );
            $json=$this->db->fetchOne(
                'SELECT plan_json FROM cos_federation_plans
                 WHERE organization_id=:org AND plan_id=:plan',
                ['org'=>$org,'plan'=>$run['source_plan_id']],
            );
            $plan=is_string($json)?json_decode($json,true,512,JSON_THROW_ON_ERROR):null;
            if(!is_array($plan)||($plan['steps']??null)!==$graph) {
                return $result('drift','approved_plan_mismatch',0,$total);
            }
            $byId=[];
            foreach($graph as $part) {
                if(!is_array($part)||!is_string($part['id']??null)
                   ||isset($byId[$part['id']]))return $result('drift','duplicate_step',0,$total);
                $byId[$part['id']]=$part;
            }
            foreach($steps as $step) {
                $sid=(string)$step['step_id'];$p=$byId[$sid]??null;
                if(!is_array($p)||$step['capability_id']!==($p['capability_id']??null)
                   ||$step['capability_version']!==($p['capability_version']??null)
                   ||$step['side_effect_level']!==($p['side_effect_level']??null)) {
                    return $result('drift','step_identity_mismatch',$verified,$total);
                }
                if($step['state']!=='completed') {
                    if(!in_array($step['state'],['claimed','pending'],true)
                       ||$step['result_reference']!==null
                       ||($step['state']==='pending'&&(int)$step['attempts']!==0)) {
                        return $result('drift','uncertain_step',$verified,$total);
                    }
                    ++$unfinished;
                    continue;
                }
                $receipt=$step['result_reference'];
                if(!is_string($receipt)||$receipt==='')return $result('drift','missing_receipt',$verified,$total);
                if($step['side_effect_level']==='external') {
                    $proof=$this->actions->attestForAudit(
                        $identity,$sid,$receipt,(string)$run['input_digest']);
                    if(!$proof->verified||$proof->stepId!==$sid
                       ||$proof->evidenceReference!==$receipt) {
                        return $result('drift','untrusted_action_receipt',$verified,$total);
                    }
                } elseif($step['side_effect_level']==='none') {
                    $this->nonActions->assertVerified(
                        $identity,$sid,$receipt,(string)$run['input_digest'],$p);
                } else return $result('drift','unknown_step_effect',$verified,$total);
                ++$verified;
            }
            if($run['state']==='completed'&&$unfinished===0&&$verified===$total) {
                return $result('verified','independent_completed_receipts',$verified,$total);
            }
            if(in_array($run['state'],['created','running'],true)) {
                return $result('incomplete','execution_not_terminal',$verified,$total);
            }
            return $result('drift','terminal_state_without_verified_receipts',$verified,$total);
        } catch(Throwable) {
            return $result('drift','approval_or_native_receipt_untrusted',$verified,$total);
        }
    }
}
