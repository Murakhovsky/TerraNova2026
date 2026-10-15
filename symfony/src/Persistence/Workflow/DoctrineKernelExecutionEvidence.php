<?php
declare(strict_types=1);
namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Platform\Workflow\Contract\WorkflowKernelExecutionEvidenceInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;

/**
 * Independently reads actual queue jobs, AgentRun results and Tool audit rows.
 * No caller-generated result or Workflow status is sufficient as a receipt.
 */
final readonly class DoctrineKernelExecutionEvidence implements WorkflowKernelExecutionEvidenceInterface
{
    public function __construct(private Connection $db) {}

    public function assertBegin(
        WorkflowRunIdentity $identity,string $kind,string $sourceId,string $capability,
        string $inputDigest,string $correlation,string $worker
    ):void {
        if ($kind==='agent') {
            $job=$this->db->fetchAssociative(
                'SELECT type,status,attempts,locked_by,correlation_id,payload
                 FROM cos_jobs WHERE organization_id=:org AND id=:job FOR UPDATE',
                ['org'=>$identity->organizationId,'job'=>$sourceId]);
            if(!$job || $job['type']!=='AGENT_RUN' || $job['status']!=='RUNNING'
                || (int)$job['attempts']!==1 || $job['locked_by']!==$worker
                || $job['correlation_id']!==$correlation) {
                throw new DomainException('Kernel Agent lacks a first, tenant-owned queue claim.');
            }
            $payload=json_decode((string)$job['payload'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($payload) || ($payload['agent_name']??null)!==$capability
                || !hash_equals($inputDigest,WorkflowDefinitionFingerprint::digest([$payload]))) {
                throw new DomainException('Kernel Agent pinned job payload changed.');
            }
            return;
        }
        if($kind!=='tool') throw new DomainException('Unknown Kernel technical execution kind.');
        if(str_starts_with($sourceId,'read:')) {
            if(strlen($sourceId)<7) throw new DomainException('Read Tool has no stable invocation key.');
            return; // Kernel Tool permission/schema gate runs BEFORE claim.
        }
        $parts=explode(':',$sourceId,3);
        if(count($parts)!==3 || $parts[0]!=='act' || !preg_match('/^[a-f0-9]{32}$/D',$parts[1])
            || $parts[2]==='') {
            throw new DomainException('Side-effect Tool must be bound to a specific approved Action.');
        }
        $a=$this->db->fetchAssociative(
            'SELECT id,source_id,type,target_type,target_id,status,execution_mode,
                    correlation_id,parameters FROM cos_actions
             WHERE organization_id=:org AND id=:action FOR UPDATE',
            ['org'=>$identity->organizationId,'action'=>$parts[1]]);
        if(!$a || $a['type']!=='kernel.tool.invoke' || $a['target_type']!=='tool'
            || $a['target_id']!==$capability || $a['status']!=='RUNNING'
            || $a['execution_mode']!=='APPROVAL_REQUIRED'
            || $a['correlation_id']!==$correlation) {
            throw new DomainException('Tool does not have an approved canonical Action in execution.');
        }
        $params=json_decode((string)$a['parameters'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($params) || ($params['tool_name']??null)!==$capability
            || ($params['tool_input_digest']??null)!==$inputDigest
            || ($params['tool_key']??null)!==$parts[2]) {
            throw new DomainException('Tool execution differs from immutable approved Action inputs.');
        }
        $latest=$this->db->fetchOne(
            'SELECT decision FROM cos_policy_evaluations
             WHERE organization_id=:org AND action_id=:action
             ORDER BY evaluated_at DESC,id DESC LIMIT 1',
            ['org'=>$identity->organizationId,'action'=>$a['id']]);
        $approvals=$this->db->fetchAllAssociative(
            'SELECT status,decided_by_type,decided_by_id,expires_at,decided_at
             FROM cos_approvals WHERE organization_id=:org AND action_id=:action',
            ['org'=>$identity->organizationId,'action'=>$a['id']]);
        if($latest!=='APPROVAL_REQUIRED' || count($approvals)!==1
            || $approvals[0]['status']!=='APPROVED'
            || $approvals[0]['decided_by_type']!=='USER'
            || !$approvals[0]['decided_at']
            || !$approvals[0]['decided_by_id']
            || $approvals[0]['decided_by_id']===$a['source_id']
            || ($approvals[0]['expires_at']!==null
                && strtotime($approvals[0]['expires_at'].' UTC')<=time())) {
            throw new DomainException('Tool Action has no independent live human approval.');
        }
        $attempts=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM cos_action_attempts
             WHERE organization_id=:org AND action_id=:action',
            ['org'=>$identity->organizationId,'action'=>$a['id']]);
        if($attempts!==1) throw new DomainException('Tool Action must have exactly one attempt.');
    }

    public function assertCompletion(
        WorkflowRunIdentity $identity,string $kind,string $sourceId,string $capability,
        string $inputDigest,string $receipt,string $correlation
    ):void {
        if($kind==='agent') {
            $rows=$this->db->fetchAllAssociative(
                'SELECT id,output,status,finished_at,context_reference FROM cos_agent_runs
                 WHERE organization_id=:org AND agent_name=:agent AND correlation_id=:trace
                 ORDER BY created_at DESC LIMIT 2',
                ['org'=>$identity->organizationId,'agent'=>$capability,'trace'=>$correlation]);
            if(count($rows)!==1 || $rows[0]['status']!=='COMPLETED'
                || $rows[0]['finished_at']===null || !is_string($rows[0]['output'])) {
                throw new DomainException('Kernel Agent receipt lacks exactly one persisted completed native AgentRun.');
            }
            $context=json_decode((string)$rows[0]['context_reference'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($context) || ($context['__kernel_source_job_id']??null)!==$sourceId) {
                throw new DomainException('Native AgentRun does not belong to the pinned queue job.');
            }
            $value=json_decode($rows[0]['output'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($value) || $value===[]) throw new DomainException('Kernel Agent output is empty.');
            $expected='kernel:agent:'.$rows[0]['id'].':'.WorkflowDefinitionFingerprint::digest([$value]);
            if(!hash_equals($expected,$receipt)) throw new DomainException('Kernel Agent native output digest mismatch.');
            $this->assertBeginAgentReadonly($identity,$sourceId,$capability,$inputDigest,$correlation);
            return;
        }
        if($kind!=='tool') throw new DomainException('Unknown canonical receipt kind.');
        if(!preg_match('/^kernel:tool:([a-f0-9]{32}):([a-f0-9]{64})$/D',$receipt,$matches)) {
            throw new DomainException('Malformed native Tool audit receipt.');
        }
        $row=$this->db->fetchAssociative(
            'SELECT id,source_type,category,action,subject_type,subject_id,
                    input_references,result,changes,metadata,correlation_id
             FROM cos_audit_log WHERE organization_id=:org AND id=:audit',
            ['org'=>$identity->organizationId,'audit'=>$matches[1]]);
        if(!$row || $row['source_type']!=='TOOL' || $row['category']!=='platform_activity'
            || $row['action']!=='tool.execute' || $row['subject_type']!=='tool'
            || $row['subject_id']!==$capability || $row['correlation_id']!==$correlation) {
            throw new DomainException('Kernel Tool receipt lacks an independent persisted audit.');
        }
        $input=json_decode((string)$row['input_references'],true,512,JSON_THROW_ON_ERROR);
        $result=json_decode((string)$row['result'],true,512,JSON_THROW_ON_ERROR);
        $changes=json_decode((string)$row['changes'],true,512,JSON_THROW_ON_ERROR);
        $metadata=json_decode((string)$row['metadata'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($input) || !is_array($result) || !is_array($changes)
            || !is_array($metadata) || ($changes['status']??null)!=='success'
            || ($metadata['tool']??null)!==null && ($metadata['tool']??null)!==$capability
            || !hash_equals($inputDigest,WorkflowDefinitionFingerprint::digest([$input]))
            || !hash_equals($matches[2],WorkflowDefinitionFingerprint::digest([$result]))) {
            throw new DomainException('Tool native audit is unsuccessful or differs from pinned input/output.');
        }
        if(str_starts_with($sourceId,'read:') && ($metadata['tool_effect']??null)!=='READ') {
            throw new DomainException('Read Tool evidence hides an external or write effect.');
        }
        if(!str_starts_with($sourceId,'read:') && ($metadata['tool_effect']??null)==='READ') {
            throw new DomainException('Approved mutation Action was paired with read-only Tool metadata.');
        }
        $this->assertBegin($identity,'tool',$sourceId,$capability,$inputDigest,$correlation,'');
    }

    private function assertBeginAgentReadonly(
        WorkflowRunIdentity $identity,string $sourceId,string $agent,string $digest,string $correlation
    ):void {
        $job=$this->db->fetchAssociative(
            'SELECT type,status,attempts,correlation_id,payload FROM cos_jobs
             WHERE organization_id=:org AND id=:job',
            ['org'=>$identity->organizationId,'job'=>$sourceId]);
        $payload=$job?json_decode((string)$job['payload'],true,512,JSON_THROW_ON_ERROR):null;
        if(!$job || $job['type']!=='AGENT_RUN' || $job['status']!=='RUNNING'
            || (int)$job['attempts']!==1 || $job['correlation_id']!==$correlation
            || !is_array($payload) || ($payload['agent_name']??null)!==$agent
            || WorkflowDefinitionFingerprint::digest([$payload])!==$digest) {
            throw new DomainException('Agent result has no pinned native source job.');
        }
    }
}
