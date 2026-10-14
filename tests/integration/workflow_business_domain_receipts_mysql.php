<?php
declare(strict_types=1);

/**
 * Independent tenant-scoped Sales / Growth / Engineering read-model receipts.
 * The fixture creates genuine domain-owned SQL records without dispatching
 * business Actions. Invalid/missing evidence MUST fail with DomainException.
 */
require (string)(getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use App\Persistence\Workflow\SalesWonDealNonActionEvidence;
use App\Persistence\Workflow\GrowthInboundResponseNonActionEvidence;
use App\Persistence\Workflow\EngineeringArtifactNonActionEvidence;
use App\Persistence\Workflow\WorkflowNonActionReceiptRegistry;
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\FailClosedWorkflowStepClaimAdmission;
use App\Persistence\Workflow\FailClosedWorkflowReceiptVerifier;
use Doctrine\DBAL\DriverManager;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;

$db=DriverManager::getConnection([
    'driver'=>'pdo_mysql','host'=>(string)(getenv('DB_HOST')?:'mysql'),
    'port'=>(int)(getenv('DB_PORT')?:3306),
    'dbname'=>(string)(getenv('DB_DATABASE')?:'cos'),
    'user'=>(string)(getenv('DB_USERNAME')?:'cos'),
    'password'=>(string)(getenv('DB_PASSWORD')?:''),'charset'=>'utf8mb4',
]);
$tag=bin2hex(random_bytes(6));
$org='wf-dom-'.$tag;
$runId='run-'.$tag;
$run=new WorkflowRunIdentity($org,$runId);
$digest=hash('sha256','source-'.$tag);
$actor=new TenantContext(
    UserId::fromString('manager-'.$tag),OrganizationId::fromString($org),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::MANAGE)],
);
$eventId=bin2hex(random_bytes(16));
$dealId='deal-'.$tag;
$payload=['source'=>'real-event','value'=>100,'won'=>true];
$salesRef='sales:deal:won:v1:'.$eventId.':'.WorkflowDefinitionFingerprint::digest([$payload]);
$actionId=bin2hex(random_bytes(16));
$replyId='response-'.$tag;$sourceId='provider-event-'.$tag;
$body='Interested buyer '.$tag;
$bodyHash=hash('sha256',$body);
$providerRef='provider-ref-'.$tag;
$growthHash=hash('sha256',implode("\0",[$bodyHash,$sourceId,$actionId,$providerRef]));
$growthRef='growth:response:recorded:v1:'.$replyId.':'.$growthHash;
$feature=bin2hex(random_bytes(16));
$engWorkflow=bin2hex(random_bytes(16));
$agent=bin2hex(random_bytes(16));
$artifact=bin2hex(random_bytes(16));
$artifactContent=['content'=>['ok'=>true,'items'=>['one','two']],'summary'=>'verified'];
$normalized=$artifactContent;
ksort($normalized);
$artifactHash=hash('sha256',json_encode($normalized,
    JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$engineeringRef='engineering:artifact:active:v1:'.$artifact.':'.$artifactHash;
$eSales=['kind'=>'sales.deal.won.v1','run_id'=>$runId,'input_digest'=>$digest,
    'event_id'=>$eventId,'deal_id'=>$dealId];
$eGrowth=['kind'=>'growth.inbound_response.recorded.v1','run_id'=>$runId,'input_digest'=>$digest,
    'response_id'=>$replyId,'source_event_id'=>$sourceId,'action_id'=>$actionId];
$eEngineering=['kind'=>'engineering.artifact.active.v1','run_id'=>$runId,'input_digest'=>$digest,
    'feature_id'=>$feature,'artifact_id'=>$artifact,'artifact_type'=>'ARCHITECTURE',
    'version'=>1,'agent_run_id'=>$agent];
$steps=[
    ['id'=>'send','capability_id'=>'test.send','capability_version'=>'1.0.0',
     'side_effect_level'=>'external','depends_on'=>[]],
    ['id'=>'sales','capability_id'=>'sales.deal_observed','capability_version'=>'1.0.0',
     'side_effect_level'=>'none','owner_domain'=>'sales','depends_on'=>[],
     'execution_binding'=>'read:sales.deal.won.v1','evidence'=>$eSales],
    ['id'=>'growth','capability_id'=>'growth.response_observed','capability_version'=>'1.0.0',
     'side_effect_level'=>'none','owner_domain'=>'growth','depends_on'=>['send'],
     'execution_binding'=>'read:growth.inbound_response.recorded.v1','evidence'=>$eGrowth],
    ['id'=>'engineering','capability_id'=>'engineering.artifact_observed','capability_version'=>'1.0.0',
     'side_effect_level'=>'none','owner_domain'=>'platform.engineering','depends_on'=>[],
     'execution_binding'=>'read:engineering.artifact.active.v1','evidence'=>$eEngineering],
];
$state=new class implements ModuleStateRepositoryInterface {
    public array $disabled=[];
    public function enabledOverride(string $org,string $module):?bool {
        return !isset($this->disabled[$module]);
    }
    public function setEnabled(string $org,string $module,bool $enabled):void {
        if($enabled)unset($this->disabled[$module]);else $this->disabled[$module]=true;
    }
};
$modules=new ActiveModuleResolver(new ModuleCatalog([
    new ModuleManifest('sales','Sales','1.0.0'),
    new ModuleManifest('growth','Growth','1.0.0'),
]),$state);
$adapters=[new SalesWonDealNonActionEvidence($db),
    new GrowthInboundResponseNonActionEvidence($db),
    new EngineeringArtifactNonActionEvidence($db)];
$registry=new WorkflowNonActionReceiptRegistry($modules,$adapters);
$store=new DoctrineWorkflowExecutionStore(
    $db,new FailClosedWorkflowReceiptVerifier(),new FailClosedWorkflowStepClaimAdmission(),
);
$deny=static function(callable $call,string $why):void{
    try{$call();}catch(DomainException){return;}
    throw new RuntimeException('Domain receipt illegally passed: '.$why);
};
$prove=static function(string $kind,string $step,string $ref,array $pin) use ($registry,$run,$digest):void{
    $registry->assertVerified($run,$step,$ref,$digest,$pin);
};
$params=['org'=>$org,'run'=>$runId];
try {
    $definition=new WorkflowDefinitionSnapshot('business-evidence','1.0.0',$steps);
    $store->create(new WorkflowRunDraft(
        $run,$definition->id,$definition->version,$digest,$actor->userId()->value(),
        'corr-'.$tag,definition:$definition,
    ),$actor);
    $db->executeStatement(
        'INSERT INTO cos_events
         (id,organization_id,type,aggregate_type,aggregate_id,payload,
          correlation_id,actor_type,actor_id,occurred_at)
         VALUES (:id,:org,"sales.deal.won","deal",:deal,:json,
                 :corr,"USER",:user,NOW(6))',
        ['id'=>$eventId,'org'=>$org,'deal'=>$dealId,
         'json'=>json_encode($payload,JSON_THROW_ON_ERROR),
         'corr'=>'corr-'.$tag,'user'=>$actor->userId()->value()],
    );
    $sent=$db->fetchOne(
        'SELECT idempotency_key FROM cos_workflow_steps
         WHERE organization_id=:org AND run_id=:run AND step_id="send"',$params,
    );
    $db->executeStatement(
        'INSERT INTO cos_actions
         (id,organization_id,type,target_type,target_id,parameters,source_type,
          source_id,status,execution_mode,risk_level,idempotency_key,correlation_id,executed_at)
         VALUES (:id,:org,"growth.send","growth","123",:json,"USER",:actor,
                 "COMPLETED","APPROVAL_REQUIRED","HIGH",:key,:corr,NOW(6))',
        ['id'=>$actionId,'org'=>$org,'json'=>'{}',
         'actor'=>$actor->userId()->value(),'key'=>'wf:'.$sent,'corr'=>'corr-'.$tag],
    );
    $db->executeStatement(
        'INSERT INTO cos_action_attempts
         (organization_id,action_id,attempt,worker_id,status,started_at,finished_at)
         VALUES (:org,:action,1,"worker","COMPLETED",NOW(6),NOW(6))',
        ['org'=>$org,'action'=>$actionId],
    );
    $db->executeStatement(
        'UPDATE cos_workflow_steps
         SET state="completed",attempts=1,revision=3,result_reference=:ref,updated_at=NOW(6)
         WHERE organization_id=:org AND run_id=:run AND step_id="send"',
        [...$params,'ref'=>'action:'.$actionId],
    );
    $db->executeStatement(
        'INSERT INTO tn_growth_engagement_responses
         (organization_id,response_id,source_event_id,execution_id,candidate_id,
          recommendation_id,action_id,channel,body,body_hash,provider_reference,
          thread_reference,occurred_at,created_at)
         VALUES (:org,:response,:source,:execution,:candidate,:recommendation,
                 :action,"email",:body,:body_hash,:provider,:thread,NOW(6),NOW(6))',
        ['org'=>$org,'response'=>$replyId,'source'=>$sourceId,
         'execution'=>'exec-'.$tag,'candidate'=>'candidate-'.$tag,
         'recommendation'=>'rec-'.$tag,'action'=>$actionId,
         'body'=>$body,'body_hash'=>$bodyHash,'provider'=>$providerRef,
         'thread'=>'thread-'.$tag],
    );
    $db->executeStatement(
        'INSERT INTO cos_engineering_features
         (id,organization_id,title,type,status,priority,request_payload,created_at,updated_at)
         VALUES (:id,:org,"Workflow evidence","FEATURE","OPEN","P1","{}",NOW(6),NOW(6))',
        ['id'=>$feature,'org'=>$org],
    );
    $db->executeStatement(
        'INSERT INTO cos_engineering_workflows
         (id,feature_id,workflow_type,current_state,status,started_at,last_activity_at,
          version,trace_id,lock_key)
         VALUES (:id,:feature,"FEATURE","DEVELOPMENT","RUNNING",NOW(6),NOW(6),
                 1,:trace,:lock)',
        ['id'=>$engWorkflow,'feature'=>$feature,'trace'=>'trace-'.$tag,'lock'=>'lock-'.$tag],
    );
    $db->executeStatement(
        'INSERT INTO cos_engineering_agent_runs
         (id,feature_id,workflow_execution_id,agent_id,agent_role,idempotency_key,
          model_provider,model,input_snapshot,output,status,technical_retry,
          logical_attempt,trace_id,started_at,finished_at)
         VALUES (:id,:feature,:workflow,"architect","PRINCIPAL_ARCHITECT",:key,
                 "test","test-model","{}",:output,"COMPLETED",0,1,:trace,NOW(6),NOW(6))',
        ['id'=>$agent,'feature'=>$feature,'workflow'=>$engWorkflow,
         'key'=>'eng-test-'.$tag,'output'=>'{"status":"ready"}','trace'=>'trace-'.$tag],
    );
    $db->executeStatement(
        'INSERT INTO cos_engineering_artifacts
         (id,feature_id,agent_run_id,type,version,status,content,content_hash,created_at)
         VALUES (:id,:feature,:agent,"ARCHITECTURE",1,"ACTIVE",:content,:digest,NOW(6))',
        ['id'=>$artifact,'feature'=>$feature,'agent'=>$agent,
         'content'=>json_encode($artifactContent,JSON_THROW_ON_ERROR),
         'digest'=>$artifactHash],
    );
    foreach([['sales',$salesRef],['growth',$growthRef],['engineering',$engineeringRef]] as [$step,$ref]){
        $db->executeStatement(
            'UPDATE cos_workflow_steps
             SET state="completed",attempts=1,revision=3,result_reference=:ref,updated_at=NOW(6)
             WHERE organization_id=:org AND run_id=:run AND step_id=:step',
            [...$params,'step'=>$step,'ref'=>$ref],
        );
    }

    $prove('sales','sales',$salesRef,$steps[1]);
    $prove('growth','growth',$growthRef,$steps[2]);
    $prove('engineering','engineering',$engineeringRef,$steps[3]);
    $deny(static fn()=>$registry->assertVerified(new WorkflowRunIdentity('another-org',$runId),
        'sales',$salesRef,$digest,$steps[1]),'Sales foreign tenant');
    $deny(static fn()=>$registry->assertVerified(new WorkflowRunIdentity('another-org',$runId),
        'engineering',$engineeringRef,$digest,$steps[3]),'Engineering foreign tenant');
    $deny(static fn()=>$registry->assertVerified($run,'growth',$growthRef,
        hash('sha256','changed input'),$steps[2]),'Growth changed Run input');
    $state->setEnabled($org,'growth',false);
    $deny(static fn()=>$prove('growth','growth',$growthRef,$steps[2]),'Growth module disabled');
    $state->setEnabled($org,'growth',true);

    $db->executeStatement('UPDATE cos_events SET type="sales.deal.lost" WHERE organization_id=:org AND id=:id',
        ['org'=>$org,'id'=>$eventId]);
    $deny(static fn()=>$prove('sales','sales',$salesRef,$steps[1]),'Sales event revoked');
    $db->executeStatement('UPDATE cos_events SET type="sales.deal.won" WHERE organization_id=:org AND id=:id',
        ['org'=>$org,'id'=>$eventId]);
    $db->executeStatement('UPDATE cos_events SET payload=:changed WHERE organization_id=:org AND id=:id',
        ['org'=>$org,'id'=>$eventId,'changed'=>'{"won":false}']);
    $deny(static fn()=>$prove('sales','sales',$salesRef,$steps[1]),'Sales event mutated');
    $db->executeStatement('UPDATE cos_events SET payload=:original WHERE organization_id=:org AND id=:id',
        ['org'=>$org,'id'=>$eventId,'original'=>json_encode($payload,JSON_THROW_ON_ERROR)]);

    $db->executeStatement(
        'UPDATE tn_growth_engagement_responses SET body="altered"
         WHERE organization_id=:org AND response_id=:id',['org'=>$org,'id'=>$replyId],
    );
    $deny(static fn()=>$prove('growth','growth',$growthRef,$steps[2]),'Growth body hash invalid');
    $db->executeStatement(
        'UPDATE tn_growth_engagement_responses SET body=:body
         WHERE organization_id=:org AND response_id=:id',['org'=>$org,'id'=>$replyId,'body'=>$body],
    );
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET state="pending"
         WHERE organization_id=:org AND run_id=:run AND step_id="send"',$params,
    );
    $deny(static fn()=>$prove('growth','growth',$growthRef,$steps[2]),'Growth linked Action not completed');
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET state="completed"
         WHERE organization_id=:org AND run_id=:run AND step_id="send"',$params,
    );

    $db->executeStatement(
        'UPDATE cos_engineering_artifacts SET status="SUPERSEDED" WHERE id=:id',['id'=>$artifact],
    );
    $deny(static fn()=>$prove('engineering','engineering',$engineeringRef,$steps[3]),'superseded Engineering artifact');
    $db->executeStatement(
        'UPDATE cos_engineering_artifacts SET status="ACTIVE",content=:changed WHERE id=:id',
        ['id'=>$artifact,'changed'=>'{"modified":true}'],
    );
    $deny(static fn()=>$prove('engineering','engineering',$engineeringRef,$steps[3]),'corrupted Engineering artifact hash');
    $db->executeStatement(
        'UPDATE cos_engineering_artifacts SET content=:original WHERE id=:id',
        ['id'=>$artifact,'original'=>json_encode($artifactContent,JSON_THROW_ON_ERROR)],
    );
    $db->executeStatement(
        'UPDATE cos_engineering_agent_runs SET status="FAILED" WHERE id=:id',['id'=>$agent],
    );
    $deny(static fn()=>$prove('engineering','engineering',$engineeringRef,$steps[3]),'failed Engineering producer');
    $db->executeStatement(
        'UPDATE cos_engineering_agent_runs SET status="COMPLETED" WHERE id=:id',['id'=>$agent],
    );
    $prove('sales','sales',$salesRef,$steps[1]);
    $prove('growth','growth',$growthRef,$steps[2]);
    $prove('engineering','engineering',$engineeringRef,$steps[3]);
    echo "Sales, Growth and Engineering real MySQL Workflow receipt adapters passed: positive, foreign tenant, revoked event/response/producer, modified source and module gates.\n";
} finally {
    $db->executeStatement('DELETE FROM cos_workflow_steps WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_runs WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_events WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM tn_growth_engagement_responses WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_action_attempts WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_actions WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_engineering_artifacts WHERE feature_id=:feature',['feature'=>$feature]);
    $db->executeStatement('DELETE FROM cos_engineering_agent_runs WHERE feature_id=:feature',['feature'=>$feature]);
    $db->executeStatement('DELETE FROM cos_engineering_workflows WHERE feature_id=:feature',['feature'=>$feature]);
    $db->executeStatement('DELETE FROM cos_engineering_features WHERE organization_id=:org',['org'=>$org]);
}
