<?php
declare(strict_types=1);
namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Agent\AgentInvocation;
use Kernel\Execution\Contract\KernelCanonicalExecutionFenceInterface;
use Kernel\Execution\KernelExecutionClaim;
use Kernel\Queue\Job;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolInvocation;
use Platform\Workflow\Contract\WorkflowKernelExecutionStoreInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;

/**
 * Opt-in boundary for general Kernel Agent and Tool runtimes. No native effect
 * is repeated if a previous technical claim is ambiguous, completed or expired.
 */
final readonly class DoctrineKernelExecutionFence implements KernelCanonicalExecutionFenceInterface
{
    public function __construct(
        private Connection $db,
        private WorkflowKernelExecutionStoreInterface $store,
    ) {}

    public function beginAgent(Job $job,string $agentName):?KernelExecutionClaim
    {
        if(!$this->enrolled($job->organizationId,'agent'))return null;
        if($job->id==='' || $job->claimedBy==='' || $job->attempts!==1
            || $agentName==='' || $job->correlationId==='') {
            throw new DomainException('Canonical Agent requires the first live queue lease.');
        }
        $source=$job->id;
        $id=$this->identity($job->organizationId,'agent',$source);
        $token=$this->store->beginKernel($id,'agent',$source,$agentName,
            WorkflowDefinitionFingerprint::digest([$job->payload]),$job->correlationId,
            $job->claimedBy,max(1,min(7200,$job->timeoutSeconds)));
        return new KernelExecutionClaim($id->organizationId,$id->runId,'agent',$token,$job->correlationId);
    }

    public function assertLegacyAgentRuntimeAllowed(string $organizationId):void
    {
        if($this->enrolled($organizationId,'agent')) {
            throw new DomainException('In-memory AgentRuntimeEngine has no canonical persisted evidence; use queued AgentRuntime.');
        }
    }

    public function assertAgentAdmitted(AgentInvocation $invocation):void
    {
        if(!$this->enrolled($invocation->organizationId,'agent')) {
            if($invocation->canonicalSourceJobId!==null || $invocation->canonicalClaimToken!==null) {
                throw new DomainException('Canonical Agent claim cannot be transferred to a legacy tenant.');
            }
            return;
        }
        $source=$invocation->canonicalSourceJobId;
        $token=$invocation->canonicalClaimToken;
        if(!is_string($source) || $source==='' || !is_string($token)
            || !preg_match('/^[a-f0-9]{32}$/D',$token)) {
            throw new DomainException('Direct Agent runtime cannot bypass canonical job admission.');
        }
        $id=$this->identity($invocation->organizationId,'agent',$source);
        $row=$this->db->fetchAssociative(
            'SELECT r.state AS run_state,r.correlation_id,s.claim_token,s.state AS step_state,
                    s.claim_expires_at,s.attempts,s.claimed_by,r.definition_json
             FROM cos_workflow_runs r
             JOIN cos_workflow_steps s ON s.organization_id=r.organization_id AND s.run_id=r.run_id
             WHERE r.organization_id=:org AND r.run_id=:run AND s.step_id="execute"',
            ['org'=>$id->organizationId,'run'=>$id->runId]);
        $steps=$row?json_decode((string)$row['definition_json'],true,512,JSON_THROW_ON_ERROR):null;
        $job=$this->db->fetchAssociative(
            'SELECT status,attempts,locked_by,locked_at,timeout_seconds,correlation_id FROM cos_jobs
             WHERE organization_id=:org AND id=:job',
            ['org'=>$id->organizationId,'job'=>$source]);
        if(!$job || $job['status']!=='RUNNING' || (int)$job['attempts']!==1
            || $job['locked_at']===null
            || strtotime((string)$job['locked_at'].' UTC') + (int)$job['timeout_seconds'] <= time()
            || ($row['claimed_by']??null)!==$job['locked_by'] || $job['correlation_id']!==$invocation->correlationId
            || !$row || $row['run_state']!=='running' || $row['step_state']!=='claimed'
            || $row['correlation_id']!==$invocation->correlationId
            || (int)$row['attempts']!==1
            || !is_string($row['claim_token']) || !hash_equals($row['claim_token'],$token)
            || $row['claim_expires_at']===null
            || strtotime((string)$row['claim_expires_at'].' UTC')<=time()
            || !is_array($steps) || count($steps)!==1
            || ($steps[0]['capability_id']??null)!==$invocation->agentName
            || ($steps[0]['source_id']??null)!==$source) {
            throw new DomainException('Agent LLM call lost active fenced canonical ownership.');
        }
    }

    public function completeAgent(KernelExecutionClaim $claim,string $nativeAgentRunId):void
    {
        if($claim->kind!=='agent' || !preg_match('/^[a-f0-9]{32}$/D',$nativeAgentRunId)) {
            throw new DomainException('Invalid persisted native AgentRun proof ID.');
        }
        $row=$this->db->fetchAssociative(
            'SELECT output,status FROM cos_agent_runs
             WHERE organization_id=:org AND id=:native AND correlation_id=:trace',
            ['org'=>$claim->organizationId,'native'=>$nativeAgentRunId,'trace'=>$claim->correlationId]);
        $output=$row?json_decode((string)($row['output']??'null'),true,512,JSON_THROW_ON_ERROR):null;
        if(!$row || $row['status']!=='COMPLETED' || !is_array($output) || $output===[]) {
            throw new DomainException('Missing durable native AgentRun result.');
        }
        $receipt='kernel:agent:'.$nativeAgentRunId.':'.WorkflowDefinitionFingerprint::digest([$output]);
        $this->store->completeKernel(new WorkflowRunIdentity($claim->organizationId,$claim->runId),
            'agent',$claim->token,$receipt,$claim->correlationId);
    }

    public function beginTool(ToolInvocation $invocation,ToolDefinition $definition,string $auditId):?KernelExecutionClaim
    {
        $org=$invocation->organizationId()->value();
        if(!$this->enrolled($org,'tool'))return null;
        $key=$invocation->idempotencyKey();
        if(!is_string($key) || $key==='' || strlen($key)>100
            || !preg_match('/^[a-f0-9]{32}$/D',$auditId)
            || $invocation->requestedBy()===null) {
            throw new DomainException('Canonical Tool requires authenticated requester and stable idempotency key.');
        }
        if($definition->effect()->hasSideEffects()) {
            $actionId=$invocation->authorizationActionId();
            if(!is_string($actionId)||!preg_match('/^[a-f0-9]{32}$/D',$actionId)) {
                throw new DomainException('Side-effect Tool requires a separately authorized Action.');
            }
            $user=$this->db->fetchOne(
                'SELECT source_id FROM cos_actions
                 WHERE organization_id=:org AND id=:action
                   AND source_type="USER" AND correlation_id=:trace',
                ['org'=>$org,'action'=>$actionId,'trace'=>$invocation->correlationId()]);
            if($user!==$invocation->requestedBy()->value()) {
                throw new DomainException('Tool requester does not own the approved Action.');
            }
            $source='act:'.$actionId.':'.$key;
        } else {
            if($invocation->authorizationActionId()!==null) {
                throw new DomainException('Read Tool cannot borrow an Action authorization.');
            }
            $source='read:'.$definition->name().':'.$key;
        }
        $id=$this->identity($org,'tool',$source);
        $token=$this->store->beginKernel(
            $id,'tool',$source,$definition->name(),
            WorkflowDefinitionFingerprint::digest([$invocation->input()]),
            $invocation->correlationId(),$auditId,600,
        );
        return new KernelExecutionClaim($org,$id->runId,'tool',$token,$invocation->correlationId());
    }

    public function completeTool(KernelExecutionClaim $claim,string $auditId):void
    {
        if($claim->kind!=='tool' || !preg_match('/^[a-f0-9]{32}$/D',$auditId)) {
            throw new DomainException('Invalid native Tool audit ID.');
        }
        $row=$this->db->fetchAssociative(
            'SELECT result FROM cos_audit_log
             WHERE organization_id=:org AND id=:native AND correlation_id=:trace',
            ['org'=>$claim->organizationId,'native'=>$auditId,'trace'=>$claim->correlationId]);
        if(!$row) throw new DomainException('Tool result was not persisted to native audit.');
        $output=json_decode((string)$row['result'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($output)) throw new DomainException('Invalid native Tool output.');
        $receipt='kernel:tool:'.$auditId.':'.WorkflowDefinitionFingerprint::digest([$output]);
        $this->store->completeKernel(new WorkflowRunIdentity($claim->organizationId,$claim->runId),
            'tool',$claim->token,$receipt,$claim->correlationId);
    }

    public function quarantine(KernelExecutionClaim $claim):void
    {
        $this->store->quarantineKernel(new WorkflowRunIdentity($claim->organizationId,$claim->runId),
            $claim->kind,$claim->token);
    }

    private function enrolled(string $org,string $kind):bool
    {
        if($org==='' || !in_array($kind,['agent','tool'],true)) {
            throw new DomainException('Invalid Kernel tenant rollout lookup.');
        }
        $row=$this->db->fetchAssociative(
            'SELECT mode,approved_by,approved_at FROM cos_kernel_execution_rollouts
             WHERE organization_id=:org AND runtime_kind=:kind',
            ['org'=>$org,'kind'=>$kind]);
        if(!$row || $row['mode']==='off')return false;
        if($row['mode']!=='canonical' || !$row['approved_at']
            || trim((string)$row['approved_by'])==='') {
            throw new DomainException('Kernel tenant canonical rollout is not operator-approved.');
        }
        return true;
    }

    private function identity(string $org,string $kind,string $source):WorkflowRunIdentity
    {
        return new WorkflowRunIdentity($org,'k'.($kind==='agent'?'a':'t').':'.substr(
            hash('sha256',$org."\0".$kind."\0".$source),0,32));
    }
}
