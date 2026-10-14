<?php
declare(strict_types=1);

/**
 * Real MySQL multi-branch Workflow DAG worker acceptance.
 * Canonical external Action evidence is actual persisted SQL; the non-Action
 * domain evidence adapter uses real persisted Documents records. No handler
 * is invoked and no production Action is dispatched.
 */
require (string)(getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use App\Persistence\Workflow\DoctrineWorkflowActionWorkerAdmission;
use App\Persistence\Workflow\DocumentSignatureNonActionEvidence;
use App\Persistence\Workflow\WorkflowNonActionReceiptRegistry;
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\FailClosedWorkflowNonActionReceiptEvidence;
use App\Persistence\Workflow\FailClosedWorkflowPlanApprovalEvidence;
use App\Persistence\Workflow\FailClosedWorkflowReceiptVerifier;
use App\Persistence\Workflow\FailClosedWorkflowStepClaimAdmission;
use App\Persistence\Workflow\WorkflowPredecessorReceiptVerifier;
use Doctrine\DBAL\DriverManager;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\CapabilityContract;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Workflow\Model\WorkflowStatus;
use Platform\Workflow\Contract\WorkflowPlanApprovalEvidenceInterface;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowRunTransition;

$db=DriverManager::getConnection([
    'driver'=>'pdo_mysql','host'=>(string)(getenv('DB_HOST')?:'mysql'),
    'port'=>(int)(getenv('DB_PORT')?:3306),
    'dbname'=>(string)(getenv('DB_DATABASE')?:'cos'),
    'user'=>(string)(getenv('DB_USERNAME')?:'cos'),
    'password'=>(string)(getenv('DB_PASSWORD')?:''),
    'charset'=>'utf8mb4',
]);
$token=bin2hex(random_bytes(6));
$org='wf-pred-'.$token;
$actorId='manager-'.$token;
$identity=new WorkflowRunIdentity($org,'pred-run-'.$token);
$planId='plan-'.$token;
$planDigest=hash('sha256','original-approved-plan-'.$token);
$approvalReference='plan-approval-'.$token;
$correlation='corr-'.$token;
$signatureId='signed-'.$token;
$documentId='document-'.$token;
$signerId='signer-'.$token;
$signatureReference='receipt-'.$token;
$definition=new WorkflowDefinitionSnapshot('test.predecessors','1.0.0',[
    ['id'=>'p1','capability_id'=>'test.publish','capability_version'=>'1.0.0',
     'owner_domain'=>'sales','execution_binding'=>'action:test.publish',
     'side_effect_level'=>'external','depends_on'=>[],
     'input'=>['target_type'=>'case','target_id'=>'p1','parameters'=>['seq'=>1]]],
    ['id'=>'p2','capability_id'=>'test.publish','capability_version'=>'1.0.0',
     'owner_domain'=>'sales','execution_binding'=>'action:test.publish',
     'side_effect_level'=>'external','depends_on'=>[],
     'input'=>['target_type'=>'case','target_id'=>'p2','parameters'=>['seq'=>2]]],
    ['id'=>'p3','capability_id'=>'documents.signature.observation','capability_version'=>'1.0.0',
     'owner_domain'=>'platform.documents',
     'execution_binding'=>'read:documents.signature.signed.v1',
     'side_effect_level'=>'none','depends_on'=>['p1'],
     'evidence'=>[
         'kind'=>'documents.signature.signed.v1','run_id'=>$identity->runId,
         'input_digest'=>hash('sha256','input-'.$token),
         'signature_id'=>$signatureId,'document_id'=>$documentId,'signer_id'=>$signerId,
     ]],
    ['id'=>'send','capability_id'=>'test.publish','capability_version'=>'1.0.0',
     'owner_domain'=>'sales','execution_binding'=>'action:test.publish',
     'side_effect_level'=>'external','depends_on'=>['p2','p3'],
     'input'=>['target_type'=>'case','target_id'=>'send','parameters'=>['seq'=>4]]],
]);
$actor=new TenantContext(
    UserId::fromString($actorId),OrganizationId::fromString($org),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::MANAGE)],
);
$draft=new WorkflowRunDraft(
    $identity,$definition->id,$definition->version,hash('sha256','input-'.$token),
    $actorId,$correlation,$planId,$approvalReference,$planDigest,$definition,
);
$catalog=new CanonicalCapabilityCatalog(new ModuleCatalog([
    new ModuleDefinition(
        new ModuleManifest('sales','Sales fixture','1.0.0'),
        new ModuleContributions(
            capabilities:['test.publish'],
            capabilityContracts:[new CapabilityContract(
                id:'test.publish',version:'1.0.0',ownerDomain:'sales',kind:'command',
                inputSchema:'resources/contracts/tests/claim.schema.json',
                outputSchema:'resources/contracts/tests/claim-output.schema.json',
                executionBinding:'action:test.publish',sideEffectLevel:'external',
                permission:'test.publish',approvalPolicy:'required',idempotency:'required',
                timeoutPolicy:['seconds'=>60],retryPolicy:['max_attempts'=>1],
                failureContract:'manual_reconciliation',
            )],
        ),
    ),
]));
$modules=new ActiveModuleResolver(
    new ModuleCatalog([new ModuleDefinition(new ModuleManifest('sales','Sales fixture','1.0.0'),new ModuleContributions())]),
    new class implements ModuleStateRepositoryInterface {
        public function enabledOverride(string $organizationId,string $moduleId):?bool {return true;}
        public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void {}
    },
);
$trustedPlan=new class($identity,$planId,$planDigest,$approvalReference) implements WorkflowPlanApprovalEvidenceInterface {
    public function __construct(
        private WorkflowRunIdentity $id,private string $plan,
        private string $digest,private string $approval,
    ){}
    public function assertTrusted(
        WorkflowRunIdentity $identity,string $planId,string $planDigest,string $approvalReference,
    ):void{
        if($identity!=$this->id || $planId!==$this->plan
            || $planDigest!==$this->digest || $approvalReference!==$this->approval) {
            throw new DomainException('Fixture Plan provenance is not trusted.');
        }
    }
};
$nonAction=new WorkflowNonActionReceiptRegistry($modules,[
    new DocumentSignatureNonActionEvidence($db),
]);
$receipts=new DoctrineWorkflowActionReceiptVerifier($db,$trustedPlan);
$predecessors=new WorkflowPredecessorReceiptVerifier($receipts,$nonAction);
$worker=new DoctrineWorkflowActionWorkerAdmission(
    $db,$trustedPlan,$catalog,$modules,$predecessors,
);
$noProofWorker=new DoctrineWorkflowActionWorkerAdmission(
    $db,$trustedPlan,$catalog,$modules,
    new WorkflowPredecessorReceiptVerifier($receipts,new FailClosedWorkflowNonActionReceiptEvidence()),
);
$noPlanWorker=new DoctrineWorkflowActionWorkerAdmission(
    $db,new FailClosedWorkflowPlanApprovalEvidence(),$catalog,$modules,$predecessors,
);
$store=new DoctrineWorkflowExecutionStore(
    $db,new FailClosedWorkflowReceiptVerifier(),new FailClosedWorkflowStepClaimAdmission(),
);
$reject=static function(callable $call,string $label):void{
    try{$call();}catch(DomainException){return;}
    throw new RuntimeException('Unsafe predecessor was accepted: '.$label);
};
$actions=[];
$stepKeys=[];
$key=['org'=>$org,'run'=>$identity->runId];
$receipt='documents:signature:signed:v1:'.$signatureId.':'
    .hash('sha256',$signatureReference);
try {
    $store->create($draft,$actor);
    $store->compareAndTransition(new WorkflowRunTransition(
        new WorkflowRunCommand($identity,$actor,1,$correlation),
        WorkflowStatus::CREATED,WorkflowStatus::RUNNING,'synthetic four-step DAG',
    ));
    $rows=$db->fetchAllAssociative(
        'SELECT step_id,idempotency_key FROM cos_workflow_steps
         WHERE organization_id=:org AND run_id=:run ORDER BY ordinal_no',$key,
    );
    if(count($rows)!==4)throw new RuntimeException('Four-step DAG was not persisted.');
    foreach($rows as $row)$stepKeys[$row['step_id']]=$row['idempotency_key'];
    foreach(['p1','p2'] as $id){
        $actionId=bin2hex(random_bytes(16));
        $actions[$id]=$actionId;
        $db->executeStatement(
            'INSERT INTO cos_actions
             (id,organization_id,type,target_type,target_id,parameters,source_type,
              source_id,status,execution_mode,risk_level,idempotency_key,
              correlation_id,executed_at)
             VALUES (:id,:org,"test.publish","case",:target,:params,"USER",
                     :actor,"COMPLETED","APPROVAL_REQUIRED","HIGH",:key,:corr,NOW(6))',
            ['id'=>$actionId,'org'=>$org,'target'=>$id,
             'params'=>json_encode(['seq'=>$id==='p1'?1:2],JSON_THROW_ON_ERROR),
             'actor'=>$actorId,'key'=>'wf:'.$stepKeys[$id],'corr'=>$correlation],
        );
        $db->executeStatement(
            'INSERT INTO cos_action_attempts
             (organization_id,action_id,attempt,worker_id,status,started_at,finished_at)
             VALUES (:org,:action,1,"canonical-worker","COMPLETED",NOW(6),NOW(6))',
            ['org'=>$org,'action'=>$actionId],
        );
        $db->executeStatement(
            'INSERT INTO cos_policy_evaluations
             (id,organization_id,action_id,decision,correlation_id,evaluated_at)
             VALUES (:id,:org,:action,"APPROVAL_REQUIRED",:corr,NOW(6))',
            ['id'=>bin2hex(random_bytes(16)),'org'=>$org,'action'=>$actionId,'corr'=>$correlation],
        );
        $db->executeStatement(
            'INSERT INTO cos_approvals
             (id,organization_id,action_id,status,approver_type,approver_id,
              requested_by_type,requested_by_id,decided_by_type,decided_by_id,
              decided_at,expires_at)
             VALUES (:id,:org,:action,"APPROVED","USER",:approver,"USER",:actor,
                     "USER",:approver,NOW(6),DATE_ADD(NOW(6),INTERVAL 1 DAY))',
            ['id'=>bin2hex(random_bytes(16)),'org'=>$org,'action'=>$actionId,
             'actor'=>$actorId,'approver'=>'approver-'.$token],
        );
        $db->executeStatement(
            'UPDATE cos_workflow_steps SET state="completed",attempts=1,revision=3,
                    result_reference=:ref,updated_at=NOW(6)
             WHERE organization_id=:org AND run_id=:run AND step_id=:step',
            [...$key,'step'=>$id,'ref'=>'action:'.$actionId],
        );
    }
    // Proof exists in the Documents domain after Run creation, not in Step flags.
    $db->executeStatement(
        'INSERT INTO cos_documents
         (organization_id,document_id,title,status,created_by,updated_by,created_at)
         VALUES (:org,:doc,"Workflow predecessor","active",1,1,NOW(6))',
        ['org'=>$org,'doc'=>$documentId],
    );
    $db->executeStatement(
        'INSERT INTO cos_document_signatures
         (organization_id,signature_id,document_id,signer_id,status,requested_by,
          signed_by,signed_by_actor_id,signature_reference,signed_at)
         VALUES (:org,:sig,:doc,:signer,"signed",1,:signer,2,:reference,NOW(6))',
        ['org'=>$org,'sig'=>$signatureId,'doc'=>$documentId,'signer'=>$signerId,
         'reference'=>$signatureReference],
    );
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET state="completed",attempts=1,revision=3,
            result_reference=:ref,updated_at=NOW(6)
         WHERE organization_id=:org AND run_id=:run AND step_id="p3"',
        [...$key,'ref'=>$receipt],
    );
    // Current action is already atomically claimed by the canonical worker.
    $currentId=bin2hex(random_bytes(16));
    $actions['send']=$currentId;
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET state="claimed",attempts=1,revision=2,
                claim_token=:claim,claimed_by="workflow-worker",
                claim_expires_at=DATE_ADD(NOW(6),INTERVAL 5 MINUTE)
         WHERE organization_id=:org AND run_id=:run AND step_id="send"',
        [...$key,'claim'=>bin2hex(random_bytes(16))],
    );
    $db->executeStatement(
        'INSERT INTO cos_actions
         (id,organization_id,type,target_type,target_id,parameters,source_type,
          source_id,status,execution_mode,risk_level,idempotency_key,correlation_id,started_at)
         VALUES (:id,:org,"test.publish","case","send",:params,"USER",:actor,
                 "RUNNING","APPROVAL_REQUIRED","HIGH",:key,:corr,NOW(6))',
        ['id'=>$currentId,'org'=>$org,
         'params'=>json_encode(['seq'=>4],JSON_THROW_ON_ERROR),
         'actor'=>$actorId,'key'=>'wf:'.$stepKeys['send'],'corr'=>$correlation],
    );
    $db->executeStatement(
        'INSERT INTO cos_action_attempts
         (organization_id,action_id,attempt,worker_id,status,started_at)
         VALUES (:org,:action,1,"canonical-worker","RUNNING",NOW(6))',
        ['org'=>$org,'action'=>$currentId],
    );
    $db->executeStatement(
        'INSERT INTO cos_policy_evaluations
         (id,organization_id,action_id,decision,correlation_id,evaluated_at)
         VALUES (:id,:org,:action,"APPROVAL_REQUIRED",:corr,NOW(6))',
        ['id'=>bin2hex(random_bytes(16)),'org'=>$org,'action'=>$currentId,'corr'=>$correlation],
    );
    $db->executeStatement(
        'INSERT INTO cos_approvals
         (id,organization_id,action_id,status,approver_type,approver_id,
          requested_by_type,requested_by_id,decided_by_type,decided_by_id,
          decided_at,expires_at)
         VALUES (:id,:org,:action,"APPROVED","USER",:approver,"USER",:actor,
                 "USER",:approver,NOW(6),DATE_ADD(NOW(6),INTERVAL 1 DAY))',
        ['id'=>bin2hex(random_bytes(16)),'org'=>$org,'action'=>$currentId,
         'actor'=>$actorId,'approver'=>'approver-'.$token],
    );
    $current=new Action($currentId,$org,'test.publish','case','send',['seq'=>4],
        'USER',$actorId,'APPROVAL_REQUIRED','HIGH','wf:'.$stepKeys['send'],
        new DateTimeImmutable(),ActionStatus::Running,$correlation);
    $worker->assertAuthorized($current);
    $reject(static fn()=> $noProofWorker->assertAuthorized($current),'no real non-Action proof adapter');
    $reject(static fn()=> $noPlanWorker->assertAuthorized($current),'untrusted business Plan');
    $db->executeStatement(
        'UPDATE cos_document_signatures SET status="revoked"
         WHERE organization_id=:org AND signature_id=:sig',
        ['org'=>$org,'sig'=>$signatureId],
    );
    $reject(static fn()=> $worker->assertAuthorized($current),'revoked independent Documents proof');
    $db->executeStatement(
        'UPDATE cos_document_signatures SET status="signed"
         WHERE organization_id=:org AND signature_id=:sig',
        ['org'=>$org,'sig'=>$signatureId],
    );

    $db->executeStatement(
        'UPDATE cos_workflow_steps SET result_reference=:bad
         WHERE organization_id=:org AND run_id=:run AND step_id="p3"',
        [...$key,'bad'=>'evidence:forged'],
    );
    $reject(static fn()=> $worker->assertAuthorized($current),'forged non-Action checkpoint reference');
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET result_reference=:good
         WHERE organization_id=:org AND run_id=:run AND step_id="p3"',
        [...$key,'good'=>$receipt],
    );
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET result_reference=:bad
         WHERE organization_id=:org AND run_id=:run AND step_id="p1"',
        [...$key,'bad'=>'action:'.bin2hex(random_bytes(16))],
    );
    $reject(static fn()=> $worker->assertAuthorized($current),'forged external canonical Action reference');
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET result_reference=:good
         WHERE organization_id=:org AND run_id=:run AND step_id="p1"',
        [...$key,'good'=>'action:'.$actions['p1']],
    );
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET idempotency_key=:bad
         WHERE organization_id=:org AND run_id=:run AND step_id="p2"',
        [...$key,'bad'=>hash('sha256','forged-step-key')],
    );
    $reject(static fn()=> $worker->assertAuthorized($current),'predecessor idempotency drift');
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET idempotency_key=:good
         WHERE organization_id=:org AND run_id=:run AND step_id="p2"',
        [...$key,'good'=>$stepKeys['p2']],
    );
    $db->executeStatement(
        'UPDATE cos_action_attempts SET status="FAILED"
         WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>$actions['p2']],
    );
    $reject(static fn()=> $worker->assertAuthorized($current),'predecessor Action failed');
    $db->executeStatement(
        'UPDATE cos_action_attempts SET status="COMPLETED"
         WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>$actions['p2']],
    );
    $db->executeStatement(
        'INSERT INTO cos_action_attempts
         (organization_id,action_id,attempt,worker_id,status,started_at,finished_at)
         VALUES (:org,:action,2,"replayed","COMPLETED",NOW(6),NOW(6))',
        ['org'=>$org,'action'=>$actions['p1']],
    );
    $reject(static fn()=> $worker->assertAuthorized($current),'multiple persisted Action attempts');
    $db->executeStatement(
        'DELETE FROM cos_action_attempts WHERE organization_id=:org AND action_id=:action AND attempt=2',
        ['org'=>$org,'action'=>$actions['p1']],
    );
    $db->executeStatement(
        'UPDATE cos_policy_evaluations SET decision="DENIED"
         WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>$actions['p2']],
    );
    $reject(static fn()=> $worker->assertAuthorized($current),'revoked predecessor Policy');
    $db->executeStatement(
        'UPDATE cos_policy_evaluations SET decision="APPROVAL_REQUIRED"
         WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>$actions['p2']],
    );
    $db->executeStatement(
        'UPDATE cos_approvals SET decided_by_id=:owner
         WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>$actions['p1'],'owner'=>$actorId],
    );
    $reject(static fn()=> $worker->assertAuthorized($current),'self-approved predecessor');
    $db->executeStatement(
        'UPDATE cos_approvals SET decided_by_id=:approver
         WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>$actions['p1'],'approver'=>'approver-'.$token],
    );
    $db->executeStatement(
        'UPDATE cos_actions SET parameters=:bad
         WHERE organization_id=:org AND id=:action',
        ['org'=>$org,'action'=>$actions['p2'],
         'bad'=>json_encode(['seq'=>99],JSON_THROW_ON_ERROR)],
    );
    $reject(static fn()=> $worker->assertAuthorized($current),'altered predecessor input');
    $db->executeStatement(
        'UPDATE cos_actions SET parameters=:original
         WHERE organization_id=:org AND id=:action',
        ['org'=>$org,'action'=>$actions['p2'],
         'original'=>json_encode(['seq'=>2],JSON_THROW_ON_ERROR)],
    );
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET attempts=2
         WHERE organization_id=:org AND run_id=:run AND step_id="p1"',$key,
    );
    $reject(static fn()=> $worker->assertAuthorized($current),'repeated technical predecessor claim');
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET attempts=1
         WHERE organization_id=:org AND run_id=:run AND step_id="p1"',$key,
    );
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET state="pending"
         WHERE organization_id=:org AND run_id=:run AND step_id="p2"',$key,
    );
    $reject(static fn()=> $worker->assertAuthorized($current),'DAG join with incomplete sibling');
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET state="completed"
         WHERE organization_id=:org AND run_id=:run AND step_id="p2"',$key,
    );
    $db->executeStatement(
        'UPDATE cos_workflow_runs SET input_digest=:bad
         WHERE organization_id=:org AND run_id=:run',
        [...$key,'bad'=>hash('sha256','changed-input')],
    );
    $reject(static fn()=> $worker->assertAuthorized($current),'changed run input breaks predecessor evidence');
    $db->executeStatement(
        'UPDATE cos_workflow_runs SET input_digest=:original
         WHERE organization_id=:org AND run_id=:run',
        [...$key,'original'=>$draft->inputDigest],
    );
    $worker->assertAuthorized($current);
    if($db->fetchOne(
        'SELECT status FROM cos_actions WHERE organization_id=:org AND id=:id',
        ['org'=>$org,'id'=>$currentId],
    )!=='RUNNING')throw new RuntimeException('Read-only worker proof changed current canonical Action.');
    echo "Workflow DAG worker MySQL receipts passed: two external branches, independently persisted Documents proof, all completed predecessor attestations, tamper/replay/Policy/Approval/DAG rejection.\n";
} finally {
    $db->executeStatement('DELETE FROM cos_approvals WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_policy_evaluations WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_action_attempts WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_actions WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_steps WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_runs WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_document_signatures WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_documents WHERE organization_id=:org',['org'=>$org]);
}
