<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use App\Persistence\Workflow\FederationWorkflowPlanApprovalEvidence;
use Doctrine\DBAL\Connection;
use DomainException;
use Platform\Workflow\Contract\WorkflowNonActionReceiptEvidenceInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;

/**
 * Read-only post-cutover integrity, not historical state parity. A migrated
 * canonical Run must be allowed to advance while the source stays frozen.
 */
final readonly class FederationPostCutoverIntegrity
{
    public function __construct(
        private Connection $db,
        private FederationWorkflowPlanApprovalEvidence $plans,
        private DoctrineWorkflowActionReceiptVerifier $actions,
        private WorkflowNonActionReceiptEvidenceInterface $nonActions,
    ) {}

    public function canonicalIdentitySha256(string $org,string $runId):string
    {
        $run=$this->db->fetchAssociative(
            'SELECT organization_id,run_id,definition_id,definition_version,
                    definition_json,definition_digest,input_digest,actor_id,
                    correlation_id,source_plan_id,source_plan_digest,approval_reference
             FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run',
            ['org'=>$org,'run'=>$runId],
        );
        if (!$run) throw new DomainException('No tenant-owned canonical Run exists for baseline.');
        $steps=$this->db->fetchAllAssociative(
            'SELECT step_id,ordinal_no,capability_id,capability_version,
                    side_effect_level,idempotency_key
             FROM cos_workflow_steps
             WHERE organization_id=:org AND run_id=:run
             ORDER BY ordinal_no,step_id',
            ['org'=>$org,'run'=>$runId],
        );
        if ($steps===[]) throw new DomainException('Canonical Run has no Steps.');
        return hash('sha256',json_encode([$run,$steps],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }

    /** @return array{status:string,findings:list<string>,checked_steps:int,verified_receipts:int,canonical_state:?string} */
    public function inspect(string $org,string $runId,array $ledger,string $sourceSha256):array
    {
        $findings=[];$verified=0;$checked=0;
        if (($ledger['migration_state']??null)!=='promoted'
            || ($ledger['parity_status']??null)!=='match'
            || ($ledger['legacy_run_id']??null)!==$runId
            || ($ledger['canonical_run_id']??null)!==$runId
            || !hash_equals((string)$ledger['source_sha256'],$sourceSha256)) {
            $findings[]='historical_cutover_baseline_not_trusted';
        }
        $baseline=$ledger['canonical_identity_sha256']??null;
        if (!is_string($baseline)||!preg_match('/^[a-f0-9]{64}$/',$baseline)) {
            $findings[]='canonical_identity_baseline_unavailable';
        }
        try {
            $current=$this->canonicalIdentitySha256($org,$runId);
            if(is_string($baseline)&&!hash_equals($baseline,$current)){
                $findings[]='canonical_immutable_identity_changed';
            }
        } catch (\Throwable) {
            $findings[]='canonical_run_or_step_missing';
        }
        $run=$this->db->fetchAssociative(
            'SELECT state,definition_json,definition_digest,input_digest,
                    source_plan_id,source_plan_digest,approval_reference
             FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run',
            ['org'=>$org,'run'=>$runId],
        );
        if (!$run) return [
            'status'=>'drift','findings'=>array_values(array_unique($findings)),
            'checked_steps'=>0,'verified_receipts'=>0,'canonical_state'=>null,
        ];
        $steps=$this->db->fetchAllAssociative(
            'SELECT step_id,state,side_effect_level,result_reference,
                    claim_expires_at,attempts
             FROM cos_workflow_steps
             WHERE organization_id=:org AND run_id=:run ORDER BY ordinal_no,step_id',
            ['org'=>$org,'run'=>$runId],
        );
        $graph=[];
        try {
            $graph=json_decode((string)$run['definition_json'],true,512,JSON_THROW_ON_ERROR);
            if (!is_array($graph)||!array_is_list($graph)
                || WorkflowDefinitionFingerprint::digest($graph)!==$run['definition_digest']) {
                throw new DomainException('Canonical graph digest mismatch.');
            }
            $this->plans->assertTrusted(new WorkflowRunIdentity($org,$runId),
                (string)$run['source_plan_id'],(string)$run['source_plan_digest'],
                (string)$run['approval_reference']);
            $planJson=$this->db->fetchOne(
                'SELECT plan_json FROM cos_federation_plans
                 WHERE organization_id=:org AND plan_id=:plan',
                ['org'=>$org,'plan'=>$run['source_plan_id']],
            );
            $approved=is_string($planJson)?json_decode($planJson,true,512,JSON_THROW_ON_ERROR):null;
            if(!is_array($approved)||($approved['steps']??null)!==$graph){
                throw new DomainException('Canonical graph differs from signed Plan.');
            }
        } catch (\Throwable) {
            $findings[]='canonical_plan_approval_or_definition_untrusted';
            $graph=[];
        }
        $byId=[];
        foreach($graph as $part) if(is_array($part)&&is_string($part['id']??null)){
            $byId[$part['id']]=$part;
        }
        foreach($steps as $step){
            ++$checked;
            $sid=(string)$step['step_id'];
            if(!isset($byId[$sid])){
                $findings[]='step_not_in_pinned_definition:'.$sid;
                continue;
            }
            if($step['state']==='completed'){
                $reference=$step['result_reference'];
                if(!is_string($reference)||$reference===''){
                    $findings[]='missing_completed_receipt:'.$sid;
                    continue;
                }
                try{
                    $identity=new WorkflowRunIdentity($org,$runId);
                    if($step['side_effect_level']==='external'){
                        $proof=$this->actions->attestForAudit(
                            $identity,$sid,$reference,(string)$run['input_digest']);
                        if(!$proof->verified||$proof->evidenceReference!==$reference){
                            throw new DomainException('No independently attested Action receipt.');
                        }
                    }elseif($step['side_effect_level']==='none'){
                        $this->nonActions->assertVerified($identity,$sid,$reference,
                            (string)$run['input_digest'],$byId[$sid]);
                    }else{
                        throw new DomainException('Unsupported canonical Step type.');
                    }
                    ++$verified;
                }catch(\Throwable){
                    $findings[]='completed_receipt_requires_reconciliation:'.$sid;
                }
            }elseif($step['state']==='pending'){
                if((int)$step['attempts']!==0||$step['result_reference']!==null){
                    $findings[]='pending_step_has_execution_evidence:'.$sid;
                }
            }elseif($step['state']==='claimed'){
                if($step['claim_expires_at']===null
                    ||strtotime((string)$step['claim_expires_at'].' UTC')<=time()){
                    $findings[]='expired_claim_requires_reconciliation:'.$sid;
                }
            }else{
                $findings[]='uncertain_step_requires_reconciliation:'.$sid;
            }
        }
        if($run['state']==='completed'&&$verified!==count($steps)){
            $findings[]='completed_run_lacks_attested_steps';
        }
        $drift=array_filter($findings,static fn(string $reason):bool=>
            str_starts_with($reason,'historical_cutover')
            ||str_starts_with($reason,'canonical_immutable')
            ||str_starts_with($reason,'canonical_run_or_step')
            ||str_starts_with($reason,'canonical_identity_baseline')
            ||str_starts_with($reason,'step_not_in_pinned'));
        return [
            'status'=>$drift!==[]?'drift':($findings!==[]?'reconciliation_required':'healthy'),
            'findings'=>array_values(array_unique($findings)),
            'checked_steps'=>$checked,'verified_receipts'=>$verified,
            'canonical_state'=>(string)$run['state'],
        ];
    }
}
