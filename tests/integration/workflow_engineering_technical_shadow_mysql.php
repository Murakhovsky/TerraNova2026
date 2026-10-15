<?php
declare(strict_types=1);

/**
 * Actual MySQL legacy Engineering AgentRun -> canonical technical execution.
 * One explicitly allowlisted tenant Feature; no LLM/GitHub calls or replay.
 */
require (string)(getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Infrastructure\Persistence\Doctrine\EngineeringCanonicalTechnicalExecutionGateway;
use App\Persistence\Federation\FederationPlanApprovalEvidenceReader;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\DoctrineWorkflowShadowParityReader;
use App\Persistence\Workflow\FailClosedWorkflowNonActionReceiptEvidence;
use App\Persistence\Workflow\FailClosedWorkflowReceiptVerifier;
use App\Persistence\Workflow\FailClosedWorkflowStepClaimAdmission;
use App\Persistence\Workflow\FederationWorkflowPlanApprovalEvidence;
use Doctrine\DBAL\DriverManager;
use Platform\Workflow\Service\WorkflowShadowParityComparator;

$db=DriverManager::getConnection([
  'driver'=>'pdo_mysql','host'=>(string)(getenv('DB_HOST')?:'mysql'),
  'port'=>(int)(getenv('DB_PORT')?:3306),
  'dbname'=>(string)(getenv('DB_DATABASE')?:'cos'),
  'user'=>(string)(getenv('DB_USERNAME')?:'cos'),
  'password'=>(string)(getenv('DB_PASSWORD')?:''),'charset'=>'utf8mb4',
]);
$tag=bin2hex(random_bytes(5));$org='eng-can-'.$tag;
$feature=EngineeringId::generate();
$workflow=EngineeringId::generate();
$agent=EngineeringId::generate();
$taskId=EngineeringId::generate();
$trace='eng-trace-'.$tag;
$task=new EngineeringAgentTask($taskId,$feature,AgentRole::QA_PLANNER,
    'Prepare deterministic fixture QA plan',[],[],[],'schema',[],
    'eng-technical-'.$tag,['logical_attempt'=>1,'revision'=>'fixed']);
$runTrace=$trace.':agent:'.$taskId;
$gate=new EngineeringCanonicalTechnicalExecutionGateway($db);
$plan=new FederationWorkflowPlanApprovalEvidence($db,new FederationPlanApprovalEvidenceReader($db));
$reader=new DoctrineWorkflowShadowParityReader($db,
    new DoctrineWorkflowExecutionStore($db,new FailClosedWorkflowReceiptVerifier(),
        new FailClosedWorkflowStepClaimAdmission()),
    new WorkflowShadowParityComparator(),$plan,
    new DoctrineWorkflowActionReceiptVerifier($db,$plan),
    new FailClosedWorkflowNonActionReceiptEvidence(),
);
$deny=static function(callable $action,string $why):void {
    try{$action();}catch(DomainException){return;}
    throw new RuntimeException('Unsafe Engineering canonical execution accepted: '.$why);
};
$createAgent=static function(string $id, string $key, string $role,string $agentTrace) use ($db,$org,$workflow,$feature):void {
    $db->executeStatement(
        'INSERT INTO cos_engineering_agent_runs
         (id,feature_id,workflow_execution_id,agent_id,agent_role,idempotency_key,
          model_provider,model,input_snapshot,status,technical_retry,logical_attempt,trace_id,started_at)
         VALUES (:id,:feature,:workflow,"fixture",:role,:key,
                 "test","test","{}","RUNNING",0,1,:trace,UTC_TIMESTAMP(6))',
        ['id'=>$id,'feature'=>$feature,'workflow'=>$workflow,'role'=>$role,'key'=>$key,'trace'=>$agentTrace],
    );
};
try {
    $db->executeStatement(
      'INSERT INTO cos_engineering_features
       (id,organization_id,title,type,status,priority,request_payload,created_at,updated_at)
       VALUES (:id,:org,"Pilot technical","FEATURE","QUEUED","P1","{}",UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',
       ['id'=>$feature,'org'=>$org],
    );
    $db->executeStatement(
      'INSERT INTO cos_engineering_workflows
       (id,feature_id,workflow_type,current_state,status,started_at,last_activity_at,version,trace_id,lock_key)
       VALUES (:id,:feature,"ENGINEERING","QA_PLANNING","RUNNING",UTC_TIMESTAMP(6),
               UTC_TIMESTAMP(6),1,:trace,:lock)',
       ['id'=>$workflow,'feature'=>$feature,'trace'=>$trace,'lock'=>'engineering:feature:'.$feature.':workflow'],
    );
    $createAgent($agent,$task->idempotencyKey,$task->role->value,$runTrace);
    $gate->begin($agent,$workflow,$task,$runTrace);
    if($reader->engineeringAgent($org,$agent)->status->value!=='no_target') {
        throw new RuntimeException('Legacy-only AgentRun must not shadow as canonical.');
    }
    $db->executeStatement(
      'INSERT INTO cos_engineering_technical_rollouts
       (organization_id,feature_id,mode,approved_by,approved_at)
       VALUES (:org,:feature,"canonical","human-operator",UTC_TIMESTAMP(6))',
       ['org'=>$org,'feature'=>$feature],
    );
    $gate->begin($agent,$workflow,$task,$runTrace);
    if($reader->engineeringAgent($org,$agent)->status->value!=='match') {
        throw new RuntimeException('Active claimed Engineering AgentRun did not match canonical technical Run.');
    }
    $link=$db->fetchAssociative(
        'SELECT * FROM cos_engineering_technical_links
         WHERE organization_id=:org AND agent_run_id=:id',
        ['org'=>$org,'id'=>$agent],
    );
    if(!$link || !preg_match('/^[a-f0-9]{32}$/',$link['claim_token'])) {
        throw new RuntimeException('Canonical claim fencing link absent.');
    }
    $other=EngineeringId::generate();
    $otherTask=new EngineeringAgentTask(EngineeringId::generate(),$feature,AgentRole::REVIEWER,
       'Reviewer fixture',[],[],[],'schema',[],'eng-other-'.$tag,['logical_attempt'=>1]);
    $createAgent($other,$otherTask->idempotencyKey,$otherTask->role->value,$trace.':agent:'.$otherTask->id);
    $deny(static fn()=> $gate->begin($other,$workflow,$otherTask,$trace.':agent:'.$otherTask->id),
        'overlapping external AgentRun while first claim is held');

    $db->executeStatement(
        'UPDATE cos_workflow_runs SET correlation_id="tampered"
         WHERE organization_id=:org AND run_id=:run',
        ['org'=>$org,'run'=>$link['canonical_run_id']],
    );
    if($reader->engineeringAgent($org,$agent)->status->value!=='drift') {
        throw new RuntimeException('Real read shadow did not detect modified canonical trace.');
    }
    $db->executeStatement(
        'UPDATE cos_workflow_runs SET correlation_id=:trace
         WHERE organization_id=:org AND run_id=:run',
        ['trace'=>$runTrace,'org'=>$org,'run'=>$link['canonical_run_id']],
    );
    $result=['status'=>'COMPLETE','report'=>['pass'=>true],'_kernel_run_id'=>'kernel-fixture'];
    $db->executeStatement(
        'UPDATE cos_engineering_agent_runs
         SET status="COMPLETED",output=:output,finished_at=UTC_TIMESTAMP(6)
         WHERE id=:agent',
        ['agent'=>$agent,'output'=>json_encode($result,JSON_THROW_ON_ERROR)],
    );
    $gate->complete($agent);
    if($reader->engineeringAgent($org,$agent)->status->value!=='match') {
        throw new RuntimeException('Completed canonical AgentRun receipt did not match persisted source output.');
    }
    $deny(static fn()=> $gate->complete($agent),'duplicate completion');
    $db->executeStatement(
        'UPDATE cos_engineering_agent_runs SET output=:fake WHERE id=:agent',
        ['agent'=>$agent,'fake'=>'{"tampered":true}'],
    );
    if($reader->engineeringAgent($org,$agent)->status->value!=='drift') {
        throw new RuntimeException('Engineering shadow did not detect forged legacy output.');
    }
    $db->executeStatement(
        'UPDATE cos_engineering_agent_runs SET output=:original WHERE id=:agent',
        ['agent'=>$agent,'original'=>json_encode($result,JSON_THROW_ON_ERROR)],
    );

    // A failed or uncertain side effect remains ambiguous, never requeued.
    $gate->begin($other,$workflow,$otherTask,$trace.':agent:'.$otherTask->id);
    $gate->quarantine($other);
    if($reader->engineeringAgent($org,$other)->status->value!=='drift') {
        // The legacy RUNNING and canonical AMBIGUOUS deliberately differ.
        throw new RuntimeException('Unresolved external AgentRun did not show shadow drift.');
    }
    $db->executeStatement(
        'UPDATE cos_engineering_agent_runs SET status="FAILED",finished_at=UTC_TIMESTAMP(6)
         WHERE id=:agent',['agent'=>$other],
    );
    if($reader->engineeringAgent($org,$other)->status->value!=='partial') {
        throw new RuntimeException('Ambiguous legacy failure cannot get MATCH parity.');
    }
    $third=EngineeringId::generate();
    $thirdTask=new EngineeringAgentTask(EngineeringId::generate(),$feature,AgentRole::DEVELOPER,
       'No replay',[],[],[],'schema',[],'eng-third-'.$tag,['logical_attempt'=>1]);
    $createAgent($third,$thirdTask->idempotencyKey,$thirdTask->role->value,$trace.':agent:'.$thirdTask->id);
    $deny(static fn()=> $gate->begin($third,$workflow,$thirdTask,$trace.':agent:'.$thirdTask->id),
        'ambiguous previous external effect cannot replay');
    if($reader->engineeringAgent('wrong-tenant',$agent)->status->value!=='no_target') {
        throw new RuntimeException('Engineering shadow leaked another tenant.');
    }
    echo "Real Engineering canonical AgentRun MySQL passed: opt-in, live shadow, claim fencing, receipts, drift, manual reconciliation and tenant isolation.\n";
} finally {
    $db->executeStatement('DELETE FROM cos_engineering_technical_links WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_steps WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_runs WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_engineering_technical_rollouts WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_engineering_agent_runs WHERE feature_id=:feature',['feature'=>$feature]);
    $db->executeStatement('DELETE FROM cos_engineering_workflows WHERE feature_id=:feature',['feature'=>$feature]);
    $db->executeStatement('DELETE FROM cos_engineering_features WHERE organization_id=:org',['org'=>$org]);
}
