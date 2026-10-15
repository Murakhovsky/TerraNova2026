<?php
declare(strict_types=1);

/** Actual MySQL acceptance: Kernel Agent and Tool share ONLY the Platform Run/Step writer. */
require (string)(getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/symfony/vendor/autoload.php');
use App\Persistence\Workflow\DoctrineKernelExecutionEvidence;
use App\Persistence\Workflow\DoctrineKernelExecutionFence;
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\FailClosedWorkflowReceiptVerifier;
use App\Persistence\Workflow\FailClosedWorkflowStepClaimAdmission;
use Doctrine\DBAL\DriverManager;
use Kernel\Queue\Job;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolEffect;

$db=DriverManager::getConnection([
 'driver'=>'pdo_mysql','host'=>(string)(getenv('DB_HOST')?:'mysql'),
 'port'=>(int)(getenv('DB_PORT')?:3306),'dbname'=>(string)(getenv('DB_DATABASE')?:'cos'),
 'user'=>(string)(getenv('DB_USERNAME')?:'cos'),
 'password'=>(string)(getenv('DB_PASSWORD')?:''),'charset'=>'utf8mb4'
]);
$token=bin2hex(random_bytes(7));
$org='kw-'.$token;$other='foreign-'.$token;
$user='manager-'.$token;$worker='worker-'.$token;
$expect=static function(bool $good,string $message):void {
 if(!$good)throw new RuntimeException($message);
};
$deny=static function(callable $operation,string $label):void {
 try{$operation();}catch(Throwable){return;}
 throw new RuntimeException('Unsafe canonical execution accepted: '.$label);
};
$evidence=new DoctrineKernelExecutionEvidence($db);
$store=new DoctrineWorkflowExecutionStore($db,new FailClosedWorkflowReceiptVerifier(),
 new FailClosedWorkflowStepClaimAdmission(),null,$evidence);
$fence=new DoctrineKernelExecutionFence($db,$store);
$jobId=bin2hex(random_bytes(16));$nativeId=bin2hex(random_bytes(16));
$trace='corr-'.$token;$agent='sales.intelligence';
$payload=['agent_name'=>$agent,'subject_type'=>'lead','subject_id'=>'lead-'.$token,
 'question'=>'Evaluate lead source','context_references'=>[]];
$toolId=bin2hex(random_bytes(16));
$readInput=['account'=>'case-'.$token,'limit'=>1];
$readName='sales.customer.read';
$toolDefinition=new ToolDefinition($readName,'read-only CI verifier',[],[],ToolEffect::READ);
$toolInvocation=new ToolInvocation(OrganizationId::fromString($org),$readName,
 $readInput,$trace,UserId::fromString($user),'read-'.$token);
try {
 $job=new Job($jobId,$org,'AGENT_RUN',$payload,1,3,7200,$trace,null,$worker);
 $expect($fence->beginAgent($job,$agent)===null,'Unenrolled Agent should retain legacy path.');
 $expect($fence->beginTool($toolInvocation,$toolDefinition,$toolId)===null,
   'Unenrolled Tool should retain legacy path.');
 foreach(['agent','tool'] as $kind) {
  $db->insert('cos_kernel_execution_rollouts',[
   'organization_id'=>$org,'runtime_kind'=>$kind,'mode'=>'canonical',
   'approved_by'=>'separate-human-reviewer-'.$token,
   'approved_at'=>gmdate('Y-m-d H:i:s.u'),
  ]);
 }
 $deny(static fn()=> $fence->beginTool(new ToolInvocation(
    OrganizationId::fromString($org),$readName,$readInput,$trace,
    UserId::fromString($user)), $toolDefinition,$toolId),
    'canonical Tool without stable idempotency key');
 $db->executeStatement(
   'INSERT INTO cos_jobs
    (id,organization_id,type,payload,status,attempts,max_attempts,timeout_seconds,
     available_at,locked_at,locked_by,correlation_id)
    VALUES (:id,:org,"AGENT_RUN",:payload,"RUNNING",1,3,7200,
            NOW(6),NOW(6),:worker,:trace)',
   ['id'=>$jobId,'org'=>$org,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),
    'worker'=>$worker,'trace'=>$trace]);
 $claim=$fence->beginAgent($job,$agent);
 $expect($claim!==null && $claim->kind==='agent','Agent canonical fence not acquired.');
 $deny(static fn()=> $fence->beginAgent($job,$agent),'duplicate Job/LLM claim');
 $deny(static fn()=> $fence->assertAgentAdmitted(new \Kernel\Agent\AgentInvocation(
    $org,'lead','lead-'.$token,'Evaluate lead source',$trace,[],$agent)),
    'direct Agent LLM bypass without token');
 $deny(static fn()=> $fence->assertAgentAdmitted(new \Kernel\Agent\AgentInvocation(
    $other,'lead','lead-'.$token,'Evaluate lead source',$trace,[],$agent,$jobId,$claim->token)),
    'cross-tenant Agent token substitution');
 $fence->assertAgentAdmitted(new \Kernel\Agent\AgentInvocation(
    $org,'lead','lead-'.$token,'Evaluate lead source',$trace,[],$agent,$jobId,$claim->token));
 $deny(static fn()=> $fence->completeAgent($claim,$nativeId),
    'Agent receipt before actual native result');
 $nativeOutput=['decision'=>'classify','confidence'=>0.83];
 $db->executeStatement(
   'INSERT INTO cos_agent_runs
    (id,organization_id,agent_name,agent_version,provider,model,
     prompt_version,schema_version,subject_type,subject_id,status,
     context_reference,input_snapshot,output,correlation_id,started_at,finished_at)
    VALUES (:id,:org,:agent,"1.0.0","fixture","fixture","1.0.0","1.0.0",
            "lead",:lead,"COMPLETED",:source,"{}",:output,:trace,NOW(6),NOW(6))',
   ['id'=>$nativeId,'org'=>$org,'agent'=>$agent,'lead'=>'lead-'.$token,
    'source'=>json_encode(['__kernel_source_job_id'=>$jobId],JSON_THROW_ON_ERROR),
    'output'=>json_encode($nativeOutput,JSON_THROW_ON_ERROR),'trace'=>$trace]);
 $fence->completeAgent($claim,$nativeId);
 $agentRun=$store->get(new \Platform\Workflow\Model\WorkflowRunIdentity($org,$claim->runId));
 $expect($agentRun?->status->value==='completed'
    && $store->steps($agentRun->identity)[0]->resultReference!==null,
    'Canonical Agent completion not independently attested.');
 $deny(static fn()=> $fence->completeAgent($claim,$nativeId),'duplicate Agent completion');
 $db->executeStatement('UPDATE cos_jobs SET status="COMPLETED",completed_at=NOW(6)
    WHERE organization_id=:org AND id=:job',
    ['org'=>$org,'job'=>$jobId]);

 // Read-only Tool: audited receipt must match native tenant, identity and input.
 $toolClaim=$fence->beginTool($toolInvocation,$toolDefinition,$toolId);
 $expect($toolClaim!==null && $toolClaim->kind==='tool','Canonical read Tool did not claim.');
 $deny(static fn()=> $fence->beginTool($toolInvocation,$toolDefinition,bin2hex(random_bytes(16))),
     'same Tool idempotency key claimed twice');
 $deny(static fn()=> $fence->completeTool($toolClaim,$toolId),
     'Tool completion without persisted audit');
 $result=['status'=>'ok','rows'=>1];
 $db->executeStatement(
   'INSERT INTO cos_audit_log
    (id,organization_id,category,actor_type,actor_id,source_type,
     subject_type,subject_id,action,input_references,changes,result,metadata,
     correlation_id,created_at)
    VALUES (:id,:org,"platform_activity","USER",:actor,"TOOL",
            "tool",:tool,"tool.execute",:input,:changes,:result,:metadata,:trace,NOW(6))',
   ['id'=>$toolId,'org'=>$org,'actor'=>$user,'tool'=>$readName,
    'input'=>json_encode($readInput,JSON_THROW_ON_ERROR),
    'changes'=>json_encode(['status'=>'success'],JSON_THROW_ON_ERROR),
    'result'=>json_encode($result,JSON_THROW_ON_ERROR),
    'metadata'=>json_encode(['tool_effect'=>'READ'],JSON_THROW_ON_ERROR),
    'trace'=>$trace]);
 $fence->completeTool($toolClaim,$toolId);
 $expect($store->get(new \Platform\Workflow\Model\WorkflowRunIdentity(
    $org,$toolClaim->runId))?->status->value==='completed',
    'Read-only Tool did not complete with native audit.');
 $deny(static fn()=> $fence->completeTool($toolClaim,$toolId),'Tool replay');
 $deny(static fn()=> $fence->beginTool(new ToolInvocation(
    OrganizationId::fromString($org),'sales.customer.write',['x'=>1],$trace,
    UserId::fromString($user),'write-'.$token),
    new ToolDefinition('sales.customer.write','external write',[],[],ToolEffect::EXTERNAL),
    bin2hex(random_bytes(16))),'mutation Tool without human approved Action');

 // Native WRITE Tool: explicit independent human approval and pinned Action attempt.
 $actionId=bin2hex(random_bytes(16));$writeAudit=bin2hex(random_bytes(16));
 $writeName='sales.customer.write';$writeKey='write-'.$token;
 $writeInput=['record_id'=>'lead-'.$token,'stage'=>'qualified'];
 $writeDigest=\Platform\Workflow\Service\WorkflowDefinitionFingerprint::digest([$writeInput]);
 $writeDefinition=new ToolDefinition($writeName,'approved mutation',[],[],ToolEffect::WRITE);
 $writeInvocation=new ToolInvocation(
    OrganizationId::fromString($org),$writeName,$writeInput,$trace,
    UserId::fromString($user),$writeKey,$actionId);
 $db->executeStatement(
    'INSERT INTO cos_actions
    (id,organization_id,type,target_type,target_id,parameters,source_type,
     source_id,status,execution_mode,risk_level,idempotency_key,correlation_id)
    VALUES (:id,:org,"kernel.tool.invoke","tool",:tool,:params,"USER",:owner,
            "RUNNING","APPROVAL_REQUIRED","HIGH",:key,:trace)',
    ['id'=>$actionId,'org'=>$org,'tool'=>$writeName,'owner'=>$user,
     'key'=>'test-kernel-tool-'.$token,'trace'=>$trace,
     'params'=>json_encode(['tool_name'=>$writeName,
       'tool_input_digest'=>$writeDigest,'tool_key'=>$writeKey],JSON_THROW_ON_ERROR)]);
 $db->executeStatement(
    'INSERT INTO cos_policy_evaluations
     (id,organization_id,action_id,decision,correlation_id,evaluated_at)
     VALUES (:id,:org,:action,"APPROVAL_REQUIRED",:trace,NOW(6))',
     ['id'=>bin2hex(random_bytes(16)),'org'=>$org,'action'=>$actionId,'trace'=>$trace]);
 $deny(static fn()=> $fence->beginTool($writeInvocation,$writeDefinition,$writeAudit),
    'WRITE Tool before independent human approval');
 $db->executeStatement(
    'INSERT INTO cos_approvals
    (id,organization_id,action_id,status,approver_type,approver_id,
     requested_by_type,requested_by_id,decided_by_type,decided_by_id,decided_at,
     expires_at)
    VALUES (:id,:org,:action,"APPROVED","USER",:reviewer,
            "USER",:owner,"USER",:reviewer,NOW(6),DATE_ADD(NOW(6),INTERVAL 1 DAY))',
    ['id'=>bin2hex(random_bytes(16)),'org'=>$org,'action'=>$actionId,
     'reviewer'=>'reviewer-'.$token,'owner'=>$user]);
 $db->executeStatement(
    'INSERT INTO cos_action_attempts
     (organization_id,action_id,attempt,worker_id,status,started_at)
     VALUES (:org,:action,1,"acceptance-worker","RUNNING",NOW(6))',
     ['org'=>$org,'action'=>$actionId]);
 $writeClaim=$fence->beginTool($writeInvocation,$writeDefinition,$writeAudit);
 $expect($writeClaim!==null,'Independently approved WRITE Tool was not admitted.');
 $db->executeStatement(
   'INSERT INTO cos_audit_log
    (id,organization_id,category,actor_type,actor_id,source_type,
     subject_type,subject_id,action,input_references,changes,result,metadata,
     correlation_id,created_at)
    VALUES (:id,:org,"platform_activity","USER",:actor,"TOOL",
            "tool",:tool,"tool.execute",:input,:changes,:result,:metadata,:trace,NOW(6))',
   ['id'=>$writeAudit,'org'=>$org,'actor'=>$user,'tool'=>$writeName,
    'input'=>json_encode($writeInput,JSON_THROW_ON_ERROR),
    'changes'=>json_encode(['status'=>'success'],JSON_THROW_ON_ERROR),
    'result'=>json_encode(['updated'=>true],JSON_THROW_ON_ERROR),
    'metadata'=>json_encode(['tool_effect'=>'WRITE'],JSON_THROW_ON_ERROR),
    'trace'=>$trace]);
 $fence->completeTool($writeClaim,$writeAudit);
 $expect($store->get(new \Platform\Workflow\Model\WorkflowRunIdentity(
    $org,$writeClaim->runId))?->status->value==='completed',
    'Native WRITE Tool independent proof was not accepted.');
 $deny(static fn()=> $fence->completeTool($writeClaim,$writeAudit),'replay of WRITE Tool');
 $db->executeStatement('UPDATE cos_actions SET status="COMPLETED",executed_at=NOW(6)
    WHERE organization_id=:org AND id=:action',
    ['org'=>$org,'action'=>$actionId]);

 // An in-flight Agent with no receipt is quarantined, never replayed.
 $job2=bin2hex(random_bytes(16));$trace2='crash-'.$token;
 $db->executeStatement(
   'INSERT INTO cos_jobs
    (id,organization_id,type,payload,status,attempts,max_attempts,timeout_seconds,
     available_at,locked_at,locked_by,correlation_id)
    VALUES (:id,:org,"AGENT_RUN",:payload,"RUNNING",1,3,7200,
            NOW(6),NOW(6),:worker,:trace)',
    ['id'=>$job2,'org'=>$org,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),
     'worker'=>$worker,'trace'=>$trace2]);
 $jobCrash=new Job($job2,$org,'AGENT_RUN',$payload,1,3,7200,$trace2,null,$worker);
 $crash=$fence->beginAgent($jobCrash,$agent);
 $fence->quarantine($crash);
 $expect($store->get(new \Platform\Workflow\Model\WorkflowRunIdentity(
    $org,$crash->runId))?->status->value==='ambiguous','Crash recovery did not quarantine.');
 $deny(static fn()=> $fence->beginAgent($jobCrash,$agent),'uncertain Agent effect automatically replayed');
 $expect((int)$db->fetchOne('SELECT COUNT(*) FROM cos_federation_runs
    WHERE organization_id=:org',['org'=>$org])===0,
    'General Kernel technical executor wrote forbidden legacy Federation Run');
 echo "Kernel canonical Agent/Tool MySQL PASSED: real queue, native AgentRun, Tool audit, fencing, duplicate replay, tenant, quarantine.\n";
} finally {
 foreach(['cos_workflow_steps','cos_workflow_runs','cos_audit_log','cos_agent_runs',
          'cos_action_attempts','cos_approvals','cos_policy_evaluations','cos_actions',
          'cos_jobs','cos_kernel_execution_rollouts'] as $table) {
  $db->executeStatement("DELETE FROM $table WHERE organization_id=:org",['org'=>$org]);
 }
}
