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
use App\Engineering\Infrastructure\Persistence\Doctrine\EngineeringCanonicalTechnicalExecutionAdmission;
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
$createAgent=static function(string $id, EngineeringAgentTask $task,string $agentTrace) use ($db,$org,$workflow,$feature):void {
    $db->executeStatement(
        'INSERT INTO cos_engineering_agent_runs
         (id,feature_id,workflow_execution_id,agent_id,agent_role,idempotency_key,
          model_provider,model,input_snapshot,status,technical_retry,logical_attempt,trace_id,started_at)
         VALUES (:id,:feature,:workflow,"fixture",:role,:key,
                 "test","test",:input,"RUNNING",0,1,:trace,UTC_TIMESTAMP(6))',
        ['id'=>$id,'feature'=>$feature,'workflow'=>$workflow,
         'role'=>$task->role->value,'key'=>$task->idempotencyKey,'trace'=>$agentTrace,
         'input'=>json_encode(array_merge($task->inputSnapshot,[
              '_execution_task_id'=>$task->id,
         ]),JSON_THROW_ON_ERROR)],
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
    $createAgent($agent,$task,$runTrace);
    // Real AgentRunStore::start synchronizes this pointer in the same transaction.
    $db->executeStatement(
        'UPDATE cos_engineering_workflows SET current_agent_run_id=:agent
         WHERE id=:workflow',
        ['agent'=>$agent,'workflow'=>$workflow],
    );
    $admission=new EngineeringCanonicalTechnicalExecutionAdmission($db,$reader);
    $admission->assertReady($task,$org,$runTrace);
    $deny(static fn()=> $admission->assertReady($task,'foreign-tenant',$runTrace),
        'legacy AgentRun tenant substitution before provider');
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
    $admission->assertReady($task,$org,$runTrace);
    $db->executeStatement(
        'UPDATE cos_engineering_workflows SET current_agent_run_id=NULL WHERE id=:workflow',
        ['workflow'=>$workflow],
    );
    $deny(static fn()=> $admission->assertReady($task,$org,$runTrace),
        'orphaned AgentRun is not authoritative workflow worker');
    $db->executeStatement(
        'UPDATE cos_engineering_workflows SET current_agent_run_id=:agent WHERE id=:workflow',
        ['agent'=>$agent,'workflow'=>$workflow],
    );
    $deny(static fn()=> $admission->assertReady($task,$org,'tampered-trace'),
        'provider trace substitution on canonical AgentRun');
    if($reader->engineeringAgent($org,$agent)->status->value!=='match') {
        throw new RuntimeException('Active claimed Engineering AgentRun did not match canonical technical Run.');
    }
    $fleet=$reader->engineeringFleet($org);
    if($fleet['status']!=='partial' || ($fleet['counts']['partial']??0)!==1
       || count($fleet['features'])!==1 || $fleet['cutover_authorized']!==false) {
        throw new RuntimeException('Real Engineering fleet audit did not preserve business gate ownership.');
    }
    // A second legacy-only workflow for this SAME opt-in Feature must not
    // disappear just because the latest canonical AgentRun is healthy.
    $legacyWorkflow=EngineeringId::generate();
    $db->executeStatement(
        'INSERT INTO cos_engineering_workflows
         (id,feature_id,workflow_type,current_state,status,started_at,
          last_activity_at,version,trace_id,lock_key)
         VALUES (:id,:feature,"ENGINEERING","ANALYSIS","RUNNING",
                 UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),1,:trace,:lock)',
        ['id'=>$legacyWorkflow,'feature'=>$feature,'trace'=>'history-'.$tag,
         'lock'=>'engineering:feature:'.$feature.':legacy-history'],
    );
    $history=$reader->engineeringFleet($org);
    if($history['status']!=='no_target'
       || $history['coverage_complete']!==true
       || count($history['features'][0]['workflows']??[])!==2
       || ($history['features'][0]['workflow_counts']['no_target']??0)!==1) {
        throw new RuntimeException('Fleet shadow masked historical Engineering Workflow missing canonical execution.');
    }
    $db->executeStatement('DELETE FROM cos_engineering_workflows WHERE id=:id',['id'=>$legacyWorkflow]);

    $batch=$reader->engineeringWorkflow($org,$workflow);
    if($batch['status']!=='partial' || ($batch['technical_counts']['match']??0)!==1
       || $batch['business_gate_authority']!=='legacy_engineering') {
        throw new RuntimeException('Engineering workflow shadow incorrectly promoted business approval.');
    }
    $link=$db->fetchAssociative(
        'SELECT * FROM cos_engineering_technical_links
         WHERE organization_id=:org AND agent_run_id=:id',
        ['org'=>$org,'id'=>$agent],
    );
    if(!$link || !preg_match('/^[a-f0-9]{32}$/',$link['claim_token'])) {
        throw new RuntimeException('Canonical claim fencing link absent.');
    }
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET claim_expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND)
         WHERE organization_id=:org AND run_id=:run AND step_id="execute"',
        ['org'=>$org,'run'=>$link['canonical_run_id']],
    );
    if($reader->engineeringAgent($org,$agent)->status->value!=='drift') {
        throw new RuntimeException('Shadow ignored expired canonical technical lease.');
    }
    $deny(static fn()=> $admission->assertReady($task,$org,$runTrace),
        'provider must not execute on expired canonical lease');
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET claim_expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 7200 SECOND)
         WHERE organization_id=:org AND run_id=:run AND step_id="execute"',
        ['org'=>$org,'run'=>$link['canonical_run_id']],
    );
    $other=EngineeringId::generate();
    $otherTask=new EngineeringAgentTask(EngineeringId::generate(),$feature,AgentRole::REVIEWER,
       'Reviewer fixture',[],[],[],'schema',[],'eng-other-'.$tag,['logical_attempt'=>1]);
    $createAgent($other,$otherTask,'resume-'.$tag.':agent:'.$otherTask->id);
    $deny(static fn()=> $gate->begin($other,$workflow,$otherTask,'resume-'.$tag.':agent:'.$otherTask->id),
        'overlapping external AgentRun while first claim is held');
    $deny(static fn()=> $admission->assertReady($otherTask,$org,'resume-'.$tag.':agent:'.$otherTask->id),
        'provider cannot bypass unresolved first canonical claim');

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
        'UPDATE cos_workflow_runs SET definition_digest=:modified
         WHERE organization_id=:org AND run_id=:run',
        ['org'=>$org,'run'=>$link['canonical_run_id'],
         'modified'=>hash('sha256','tampered-workflow-definition')],
    );
    if($reader->engineeringAgent($org,$agent)->status->value!=='drift') {
        throw new RuntimeException('SQL shadow ignored canonical immutable graph digest tampering.');
    }
    $canonicalGraph=$db->fetchOne(
        'SELECT definition_json FROM cos_workflow_runs
         WHERE organization_id=:org AND run_id=:run',
        ['org'=>$org,'run'=>$link['canonical_run_id']],
    );
    $graph=json_decode((string)$canonicalGraph,true,512,JSON_THROW_ON_ERROR);
    $db->executeStatement(
        'UPDATE cos_workflow_runs SET definition_digest=:digest
         WHERE organization_id=:org AND run_id=:run',
        ['org'=>$org,'run'=>$link['canonical_run_id'],
         'digest'=>\Platform\Workflow\Service\WorkflowDefinitionFingerprint::digest($graph)],
    );
    $db->executeStatement(
        'UPDATE cos_engineering_agent_runs
         SET status="COMPLETED",output=:output,finished_at=UTC_TIMESTAMP(6)
         WHERE id=:agent',
        ['agent'=>$agent,'output'=>json_encode($result,JSON_THROW_ON_ERROR)],
    );
    $gate->complete($agent);
    $deny(static fn()=> $admission->assertReady($task,$org,$runTrace),
        'finished AgentRun may not execute provider again');
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
    $gate->begin($other,$workflow,$otherTask,'resume-'.$tag.':agent:'.$otherTask->id);
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
    $createAgent($third,$thirdTask,'second-resume-'.$tag.':agent:'.$thirdTask->id);
    $deny(static fn()=> $gate->begin($third,$workflow,$thirdTask,'second-resume-'.$tag.':agent:'.$thirdTask->id),
        'ambiguous previous external effect cannot replay');
    if($reader->engineeringAgent('wrong-tenant',$agent)->status->value!=='no_target') {
        throw new RuntimeException('Engineering shadow leaked another tenant.');
    }
    echo "Real Engineering canonical AgentRun MySQL passed: opt-in, fleet parity, pre-provider admission, claim fencing, receipts, drift, manual reconciliation and tenant isolation.\n";
} finally {
    $db->executeStatement('DELETE FROM cos_engineering_technical_links WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_steps WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_runs WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_engineering_technical_rollouts WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_engineering_agent_runs WHERE feature_id=:feature',['feature'=>$feature]);
    $db->executeStatement('DELETE FROM cos_engineering_workflows WHERE feature_id=:feature',['feature'=>$feature]);
    $db->executeStatement('DELETE FROM cos_engineering_features WHERE organization_id=:org',['org'=>$org]);
}
