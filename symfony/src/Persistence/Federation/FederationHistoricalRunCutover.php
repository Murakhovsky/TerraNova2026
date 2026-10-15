<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use App\Persistence\Workflow\DoctrineWorkflowShadowParityReader;
use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Workflow\Model\WorkflowStatus;
use Platform\Workflow\Contract\WorkflowExecutionStoreInterface;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowRunTransition;

/**
 * Operator-only no-replay migration. The canonical Store is the ONLY technical
 * Run/Step writer. Completed/claimed/failed or ambiguous old Actions are NOT
 * rekeyed from fed:* to wf:*; they require separate immutable archival proof.
 *
 * No user-facing controller, cron job or per-step retry.
 */
final readonly class FederationHistoricalRunCutover
{
    public function __construct(
        private Connection $db,
        private WorkflowExecutionStoreInterface $store,
        private DoctrineWorkflowShadowParityReader $parity,
        private FederationPlanApprovalEvidenceReader $approvals,
    ) {}

    /** @return array<string,mixed> */
    public function inspect(string $org, string $runId): array
    {
        $source=$this->source($org,$runId);
        $reasons=$this->blockers($org,$source);
        $ledger=$this->db->fetchAssociative(
            'SELECT migration_state,source_sha256,parity_status,canonical_run_id
             FROM cos_federation_execution_migrations
             WHERE organization_id=:org AND legacy_run_id=:run',
            ['org'=>$org,'run'=>$runId],
        );
        if ($ledger) {
            $now=$this->sourceDigest($source);
            if (!hash_equals((string)$ledger['source_sha256'],$now)) $reasons[]='legacy_source_mutated_after_cutover';
        }
        return [
            'organization_id'=>$org,'run_id'=>$runId,
            'legacy_state'=>$source['run']['state'],
            'legacy_step_count'=>count($source['steps']),
            'migration_state'=>$ledger['migration_state']??'not_migrated',
            'source_sha256'=>$this->sourceDigest($source),
            'promotable'=>$reasons===[] && $ledger===false,
            'reasons'=>array_values(array_unique($reasons)),
            'parity'=>$ledger ? $this->parity->federation($org,$runId)->status->value : 'no_target',
            'read_only'=>true,
            // Operator diagnosis is read-only; no Action claim, queue or retry.
            'step_reconciliation'=>$this->stepReconciliation($org,$source['steps']),
        ];
    }

    /** @param list<array<string,mixed>> $steps
     *  @return list<array{step_id:string,side_effect_level:string,legacy_state:string,
     *      legacy_attempts:int,action_id:?string,action_status:?string,
     *      persisted_action_attempts:int,diagnosis:string}>
     */
    private function stepReconciliation(string $org,array $steps):array
    {
        $items=[];
        foreach($steps as $step){
            $side=(string)$step['side_effect_level'];
            $key=(string)$step['idempotency_key'];
            $attempts=(int)$step['attempts'];
            $receipt=$step['result_reference'];
            $actions=$this->db->fetchAllAssociative(
                'SELECT id,status FROM cos_actions
                 WHERE organization_id=:org AND idempotency_key=:key',
                ['org'=>$org,'key'=>'fed:'.$key],
            );
            $action=count($actions)===1?$actions[0]:null;
            // DBAL fetchAllAssociative returns arrays, not entities.
            $actionId=is_array($action)?(string)$action['id']:null;
            $attemptRows=$actionId===null?[]:$this->db->fetchAllAssociative(
                'SELECT attempt,status FROM cos_action_attempts
                 WHERE organization_id=:org AND action_id=:action ORDER BY attempt',
                ['org'=>$org,'action'=>$actionId],
            );
            $diagnosis='manual_reconciliation_required';
            if ($side==='none') {
                $diagnosis=$step['state']==='completed'
                    ? 'domain_receipt_attestation_required'
                    : 'non_action_legacy_state_review';
            } elseif($side==='external' && count($actions)===0
                && $step['state']==='pending' && $attempts===0 && $receipt===null) {
                $diagnosis='not_dispatched_pristine';
            } elseif($side==='external' && $action!==null
                && $action['status']==='COMPLETED'
                && count($attemptRows)===1
                && (int)$attemptRows[0]['attempt']===1
                && $attemptRows[0]['status']==='COMPLETED'
                && $receipt==='action:'.$actionId) {
                // Correlation is diagnostic ONLY. FederatedActionAdmission must
                // re-attest Plan, Approval, Policy and Action before any archive.
                $diagnosis='independent_action_receipt_attestation_required';
            }
            $items[]=[
                'step_id'=>(string)$step['step_id'],
                'side_effect_level'=>$side,
                'legacy_state'=>(string)$step['state'],
                'legacy_attempts'=>$attempts,
                'action_id'=>$actionId,
                'action_status'=>$action['status']??null,
                'persisted_action_attempts'=>count($attemptRows),
                'diagnosis'=>$diagnosis,
            ];
        }
        return $items;
    }

    /** @return array<string,mixed> */
    public function promotePristine(string $org,string $runId,string $operator): array
    {
        if (getenv('COS_FEDERATION_HISTORICAL_MIGRATION')!=='explicit_operator_approved'
            || trim($operator)==='') {
            throw new DomainException('Historical migration requires explicit operator authorization.');
        }
        return $this->db->transactional(function()use($org,$runId,$operator):array{
            // All legacy writers lock this exact Run row. Do not copy while a
            // legacy Action or another operator may be claiming a Step.
            $source=$this->source($org,$runId,true);
            $reasons=$this->blockers($org,$source);
            if ($reasons!==[]) throw new DomainException('Historical Run requires reconciliation: '.implode(',',$reasons));
            $run=$source['run'];
            $plan=$source['plan'];
            $owner=(string)$source['owner_id'];
            $actor=new TenantContext(
                UserId::fromString($owner),OrganizationId::fromString($org),
                OrganizationRole::fromString('manager'),
                [Permission::fromString(TenantPermissions::MANAGE)],
            );
            // Provenance is independently checked using source Action, Policy,
            // human Approval and the immutable Plan hash. Never trust state=approved alone.
            $approval=$this->db->fetchAllAssociative(
                'SELECT id FROM cos_actions WHERE organization_id=:org
                   AND type=:type AND target_type=:target_type AND target_id=:plan
                   AND status=:completed',
                ['org'=>$org,'type'=>'cos.federation.plan.approval',
                    'target_type'=>'cos_federation_plan','plan'=>$run['plan_id'],
                    'completed'=>'COMPLETED'],
            );
            if (count($approval)!==1) throw new DomainException('Historical Plan has no unique completed Approval Action.');
            $approvalId=(string)$approval[0]['id'];
            $this->approvals->requireApproval($actor,$approvalId,(string)$run['goal_id'],
                (string)$run['plan_id'],(int)$plan['spec_version'],
                (string)$plan['plan_json'],$owner);
            $definition=new WorkflowDefinitionSnapshot('federation.approved-plan','1.0.0',$source['definition']);
            $identity=new WorkflowRunIdentity($org,$runId);
            $digest=hash('sha256',(string)$plan['plan_json']);
            $input=$source['input_digest']??$digest;
            $draft=new WorkflowRunDraft($identity,$definition->id,$definition->version,
                $input,$owner,'federation:'.$runId,(string)$run['plan_id'],
                $approvalId,$digest,$definition);
            $this->store->create($draft,$actor);
            if ($run['state']==='running') {
                $this->store->compareAndTransition(new WorkflowRunTransition(
                    new WorkflowRunCommand($identity,$actor,1,'federation:'.$runId),
                    WorkflowStatus::CREATED,WorkflowStatus::RUNNING,'verified legacy pristine Run cutover'));
            }
            $this->db->insert('cos_federation_execution_migrations',[
                'organization_id'=>$org,'legacy_run_id'=>$runId,
                'plan_id'=>(string)$run['plan_id'],'canonical_run_id'=>$runId,
                'source_sha256'=>$this->sourceDigest($source),
                'approval_reference'=>$approvalId,
                'parity_status'=>'pending','migration_state'=>'promoted',
                'operator_id'=>$operator,'migrated_at'=>gmdate('Y-m-d H:i:s.u'),
            ]);
            $parity=$this->parity->federation($org,$runId);
            if ($parity->status->value!=='match') {
                throw new DomainException('Historical cutover rejected: '.implode(',',$parity->differences));
            }
            $this->db->executeStatement(
                'UPDATE cos_federation_execution_migrations SET parity_status=:status
                 WHERE organization_id=:org AND legacy_run_id=:run',
                ['status'=>'match','org'=>$org,'run'=>$runId],
            );
            return ['organization_id'=>$org,'run_id'=>$runId,
                'state'=>'promoted','parity'=>'match','source_sha256'=>$this->sourceDigest($source),
                'action_replays'=>0,'technical_writer'=>'platform_workflow'];
        });
    }

    /** @return array{run:array<string,mixed>,plan:array<string,mixed>,steps:list<array<string,mixed>>,definition:list<array<string,mixed>>,owner_id:string,input_digest?:string} */
    private function source(string $org,string $runId,bool $lock=false):array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/',$runId) || trim($org)==='') {
            throw new DomainException('Historical source requires a valid tenant and Run.');
        }
        $row=$this->db->fetchAssociative(
            'SELECT * FROM cos_federation_runs WHERE organization_id=:org AND run_id=:run'
            .($lock?' FOR UPDATE':''),
            ['org'=>$org,'run'=>$runId],
        );
        if (!$row) throw new DomainException('Historical Federation Run does not exist.');
        $plan=$this->db->fetchAssociative(
            'SELECT * FROM cos_federation_plans WHERE organization_id=:org AND plan_id=:plan'
            .($lock?' FOR UPDATE':''),
            ['org'=>$org,'plan'=>$row['plan_id']],
        );
        $owner=$this->db->fetchOne(
            'SELECT owner_id FROM cos_federation_goals WHERE organization_id=:org AND goal_id=:goal',
            ['org'=>$org,'goal'=>$row['goal_id']],
        );
        if (!$plan || !is_string($owner) || $owner==='') {
            throw new DomainException('Historical Run Plan/Goal ownership is missing.');
        }
        $steps=$this->db->fetchAllAssociative(
            'SELECT * FROM cos_federation_steps WHERE organization_id=:org AND run_id=:run ORDER BY step_id'
            .($lock?' FOR UPDATE':''),
            ['org'=>$org,'run'=>$runId],
        );
        $decoded=json_decode((string)$plan['plan_json'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($decoded['steps']??null)
            || !array_is_list($decoded['steps'])) {
            throw new DomainException('Historical immutable Plan lacks a valid graph.');
        }
        $source=['run'=>$row,'plan'=>$plan,'steps'=>$steps,'definition'=>$decoded['steps'],'owner_id'=>$owner];
        $hasNonAction=false;
        foreach($decoded['steps'] as $step) {
            if (($step['side_effect_level']??null)==='none') $hasNonAction=true;
        }
        if($hasNonAction) {
            $source['input_digest']=hash('sha256',$org."\0".$runId."\0".$row['plan_id']);
        }
        return $source;
    }

    /** @param array<string,mixed> $source @return list<string> */
    private function blockers(string $org,array $source):array
    {
        $r=$source['run'];$steps=$source['steps'];$graph=$source['definition'];
        $reasons=[];
        if (!in_array($r['state'],['pending','running'],true)
            || !in_array((int)$r['revision'],[1,2],true)
            || ($r['state']==='pending' && (int)$r['revision']!==1)
            || ($r['state']==='running' && (int)$r['revision']!==2)) {
            $reasons[]='historical_terminal_or_mutated_run_requires_manual_archive';
        }
        if ($source['plan']['state']!=='approved') $reasons[]='plan_not_approved';
        $currentSpec=$this->db->fetchOne(
            'SELECT current_spec_version FROM cos_federation_goals
             WHERE organization_id=:org AND goal_id=:goal',
            ['org'=>$org,'goal'=>$r['goal_id']],
        );
        if ((int)$currentSpec!==(int)$source['plan']['spec_version']) {
            $reasons[]='approved_specification_no_longer_current';
        }
        // Read-only preflight must attest the original independent Plan human
        // approval. An "approved" string is insufficient for promotion.
        $approvals=$this->db->fetchAllAssociative(
            'SELECT id FROM cos_actions WHERE organization_id=:org
             AND type=:type AND target_type=:target
             AND target_id=:plan AND status=:status',
            ['org'=>$org,'type'=>'cos.federation.plan.approval',
                'target'=>'cos_federation_plan','plan'=>$r['plan_id'],
                'status'=>'COMPLETED'],
        );
        if (count($approvals)!==1) {
            $reasons[]='plan_approval_not_unique';
        } else {
            try {
                $this->approvals->requireForOrganization($org,(string)$approvals[0]['id'],
                    (string)$r['goal_id'],(string)$r['plan_id'],
                    (int)$source['plan']['spec_version'],(string)$source['plan']['plan_json'],
                    (string)$source['owner_id']);
            } catch (\Throwable) {
                $reasons[]='plan_approval_not_attested';
            }
        }
        if (count($steps)!==count($graph) || $steps===[]) $reasons[]='step_topology_mismatch';
        $byId=[];
        foreach($graph as $step) {
            if (!is_array($step) || !is_string($step['id']??null)) {
                $reasons[]='invalid_pinned_step';continue;
            }
            $byId[$step['id']]=$step;
        }
        foreach($steps as $step){
            $p=$byId[$step['step_id']]??null;
            $expected=hash('sha256',$org."\0".$r['run_id']."\0".$step['step_id']);
            if (!is_array($p) || $step['state']!=='pending' || (int)$step['attempts']!==0
                || $step['result_reference']!==null || $step['idempotency_key']!==$expected
                || $step['capability_id']!==($p['capability_id']??null)
                || $step['capability_version']!==($p['capability_version']??null)
                || $step['side_effect_level']!==($p['side_effect_level']??null)) {
                $reasons[]='step_executed_claimed_or_changed';continue;
            }
            $count=(int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM cos_actions WHERE organization_id=:org
                 AND idempotency_key IN (:old,:new)',
                ['org'=>$org,'old'=>'fed:'.$expected,'new'=>'wf:'.$expected],
            );
            if ($count!==0) $reasons[]='existing_action_no_replay';
            if (($p['side_effect_level']??null)==='none') {
                $plan=json_decode((string)$source['plan']['plan_json'],true,512,JSON_THROW_ON_ERROR);
                if (($plan['run_id']??null)!==$r['run_id']
                    || ($p['evidence']['run_id']??null)!==$r['run_id']
                    || ($p['evidence']['input_digest']??null)!==($source['input_digest']??null)) {
                    $reasons[]='unpinned_non_action_evidence';
                }
            } elseif(($p['side_effect_level']??null)!=='external') {
                $reasons[]='unsupported_side_effect_level';
            }
        }
        $existing=$this->db->fetchOne(
            'SELECT run_id FROM cos_workflow_runs WHERE organization_id=:org
             AND (run_id=:run OR source_plan_id=:plan)',
            ['org'=>$org,'run'=>$r['run_id'],'plan'=>$r['plan_id']],
        );
        if ($existing!==false) $reasons[]='canonical_authority_already_exists';
        $ledger=$this->db->fetchOne(
            'SELECT legacy_run_id FROM cos_federation_execution_migrations
             WHERE organization_id=:org AND legacy_run_id=:run',
            ['org'=>$org,'run'=>$r['run_id']],
        );
        if ($ledger!==false) $reasons[]='historical_migration_already_recorded';
        return array_values(array_unique($reasons));
    }

    /** @param array<string,mixed> $source */
    private function sourceDigest(array $source):string
    {
        return hash('sha256',json_encode([
            $source['run'],$source['steps'],$source['plan']['plan_json']
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }
}
