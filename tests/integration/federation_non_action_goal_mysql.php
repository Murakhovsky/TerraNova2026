<?php
declare(strict_types=1);

/**
 * Real MySQL non-Action Federation technical execution. A pinned Documents
 * signature supplies the receipt; no Action handler is invoked or synthesized.
 */
require (string)(getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use App\Persistence\Federation\FederationPlanApprovalEvidenceReader;
use App\Persistence\Workflow\FederationWorkflowPlanApprovalEvidence;
use App\Persistence\Workflow\DoctrineWorkflowActionClaimAdmission;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use App\Persistence\Workflow\FederationNonActionClaimAdmission;
use App\Persistence\Workflow\WorkflowClaimAdmissionRouter;
use App\Persistence\Workflow\WorkflowReceiptAttestationRouter;
use App\Persistence\Workflow\WorkflowNonActionReceiptRegistry;
use App\Persistence\Workflow\DocumentSignatureNonActionEvidence;
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use Doctrine\DBAL\DriverManager;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Workflow\Model\StepStatus;
use Kernel\Workflow\Model\WorkflowStatus;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunTransition;
use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Model\WorkflowStepCheckpoint;

$db=DriverManager::getConnection([
 'driver'=>'pdo_mysql','host'=>(string)(getenv('DB_HOST')?:'mysql'),
 'port'=>(int)(getenv('DB_PORT')?:3306),'dbname'=>(string)(getenv('DB_DATABASE')?:'cos'),
 'user'=>(string)(getenv('DB_USERNAME')?:'cos'),
 'password'=>(string)(getenv('DB_PASSWORD')?:''),'charset'=>'utf8mb4',
]);
$token=bin2hex(random_bytes(6));
$org='wf-goal-read-'.$token;$owner='owner-'.$token;$other='approver-'.$token;
$goal='goal-'.$token;$plan='plan-'.$token;$run='run-'.$token;
$sig='sig-'.$token;$document='doc-'.$token;$signer='signer-'.$token;
$approvalId=bin2hex(random_bytes(16));
$input=hash('sha256',$org."\0".$run."\0".$plan);
$signatureRef='signed-document-'.$token;
$reference='documents:signature:signed:v1:'.$sig.':'.hash('sha256',$signatureRef);
$step=[
 'id'=>'doc','capability_id'=>'documents.signature.observation',
 'capability_version'=>'1.0.0','side_effect_level'=>'none','owner_domain'=>'platform.documents',
 'execution_binding'=>'read:documents.signature.signed.v1','depends_on'=>[],
 'evidence'=>[
   'kind'=>'documents.signature.signed.v1','run_id'=>$run,'input_digest'=>$input,
   'reference'=>$reference,'signature_id'=>$sig,'document_id'=>$document,'signer_id'=>$signer,
 ],
];
$actor=new TenantContext(UserId::fromString($owner),OrganizationId::fromString($org),
    OrganizationRole::fromString('manager'),[Permission::fromString(TenantPermissions::MANAGE)]);
$modules=new ActiveModuleResolver(new ModuleCatalog([]),
    new class implements ModuleStateRepositoryInterface {
       public function enabledOverride(string $organizationId,string $moduleId):?bool{return null;}
       public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void{}
    });
$catalog=new CanonicalCapabilityCatalog(new ModuleCatalog([]));
$reader=new FederationPlanApprovalEvidenceReader($db);
$plans=new FederationWorkflowPlanApprovalEvidence($db,$reader);
$registry=new WorkflowNonActionReceiptRegistry($modules,[new DocumentSignatureNonActionEvidence($db)]);
$receipt=new WorkflowReceiptAttestationRouter(
    $db,new DoctrineWorkflowActionReceiptVerifier($db,$plans),$registry,$plans);
$admission=new WorkflowClaimAdmissionRouter(
    new DoctrineWorkflowActionClaimAdmission($db,$plans,$catalog,$modules),
    new FederationNonActionClaimAdmission($db,$plans),
);
$store=new DoctrineWorkflowExecutionStore($db,$receipt,$admission);
$assert=static function(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);};
$reject=static function(callable $f,string $reason):void{
 try{$f();}catch(Throwable){return;}
 throw new RuntimeException('Non-Action receipt bypass: '.$reason);
};
$id=new WorkflowRunIdentity($org,$run);
try {
    $db->executeStatement(
       'INSERT INTO cos_federation_goals
        (organization_id,goal_id,owner_id,current_spec_version,state,revision,created_at,updated_at)
        VALUES (:org,:goal,:owner,1,"draft",1,NOW(6),NOW(6))',
       ['org'=>$org,'goal'=>$goal,'owner'=>$owner]);
    $planJson=json_encode(['run_id'=>$run,'steps'=>[$step]],JSON_THROW_ON_ERROR);
    $db->executeStatement(
       'INSERT INTO cos_federation_plans
        (organization_id,plan_id,goal_id,spec_version,plan_version,state,plan_json,created_at)
        VALUES (:org,:plan,:goal,1,1,"approved",:json,NOW(6))',
       ['org'=>$org,'plan'=>$plan,'goal'=>$goal,'json'=>$planJson]);
    $stored=(string)$db->fetchOne(
       'SELECT plan_json FROM cos_federation_plans WHERE organization_id=:org AND plan_id=:plan',
       ['org'=>$org,'plan'=>$plan]);
    $digest=hash('sha256',$stored);
    $db->executeStatement(
       'INSERT INTO cos_actions
        (id,organization_id,type,target_type,target_id,parameters,source_type,source_id,
         status,execution_mode,risk_level,idempotency_key,correlation_id,executed_at)
        VALUES (:id,:org,"cos.federation.plan.approval","cos_federation_plan",:plan,:params,
         "USER",:owner,"COMPLETED","APPROVAL_REQUIRED","HIGH",:key,:correlation,NOW(6))',
       ['id'=>$approvalId,'org'=>$org,'plan'=>$plan,'owner'=>$owner,
        'params'=>json_encode(['goal_id'=>$goal,'plan_id'=>$plan,
            'specification_version'=>1,'plan_hash'=>$digest],JSON_THROW_ON_ERROR),
        'key'=>'approved-plan:'.$token,'correlation'=>'approved-'.$token]);
    $db->executeStatement(
      'INSERT INTO cos_policy_evaluations
       (id,organization_id,action_id,decision,correlation_id,evaluated_at)
       VALUES (:id,:org,:action,"APPROVAL_REQUIRED",:correlation,NOW(6))',
      ['id'=>bin2hex(random_bytes(16)),'org'=>$org,'action'=>$approvalId,
       'correlation'=>'approved-'.$token]);
    $db->executeStatement(
      'INSERT INTO cos_approvals
       (id,organization_id,action_id,status,approver_type,approver_id,
        requested_by_type,requested_by_id,decided_by_type,decided_by_id,
        decided_at,expires_at)
       VALUES (:id,:org,:action,"APPROVED","USER",:other,"USER",:owner,
               "USER",:other,NOW(6),DATE_ADD(NOW(6),INTERVAL 1 DAY))',
      ['id'=>bin2hex(random_bytes(16)),'org'=>$org,'action'=>$approvalId,
       'other'=>$other,'owner'=>$owner]);
    $definition=new WorkflowDefinitionSnapshot('federation.approved-plan','1.0.0',[$step]);
    $store->create(new WorkflowRunDraft($id,$definition->id,$definition->version,$input,
        $owner,'federation:'.$run,$plan,$approvalId,$digest,$definition),$actor);
    $cmd=static fn(int $revision):WorkflowRunCommand=>new WorkflowRunCommand(
        $id,$actor,$revision,'federation:'.$run);
    $store->compareAndTransition(new WorkflowRunTransition(
        $cmd(1),WorkflowStatus::CREATED,WorkflowStatus::RUNNING,'trusted observation started'));
    $claimed=$store->claim(new WorkflowStepClaim($cmd(2),'doc','ci-evidence-only',120));
    $assert($claimed->state==='claimed'&&is_string($claimed->claimToken),
        'Platform Store failed to reserve evidence-only Step');
    $reject(static fn()=> $store->claim(new WorkflowStepClaim($cmd(3),'doc','retry',120)),
        'second Step claim');
    $reject(static fn()=> $store->checkpoint(new WorkflowStepCheckpoint(
        $cmd(3),'doc',$claimed->claimToken,StepStatus::COMPLETED,$reference)),
        'no authoritative Documents signature');
    $assert($store->steps($id)[0]->state==='claimed',
        'Missing receipt modified the canonical Step state');

    // Source fact arrives AFTER claim. The receipt verifier checks the exact
    // source identity and time interval at the eventual checkpoint.
    usleep(120000);
    $db->executeStatement(
       'INSERT INTO cos_documents
        (organization_id,document_id,title,status,created_by,updated_by,created_at)
        VALUES (:org,:doc,"Canonical evidence test","active",1,1,NOW(6))',
       ['org'=>$org,'doc'=>$document]);
    $db->executeStatement(
       'INSERT INTO cos_document_signatures
        (organization_id,signature_id,document_id,signer_id,status,requested_by,
         signed_by,signed_by_actor_id,signature_reference,signed_at)
        VALUES (:org,:sig,:doc,:signer,"signed",1,:signer,2,:reference,NOW(6))',
       ['org'=>$org,'sig'=>$sig,'doc'=>$document,'signer'=>$signer,'reference'=>$signatureRef]);
    usleep(120000);
    $reject(static fn()=> $store->checkpoint(new WorkflowStepCheckpoint(
        $cmd(3),'doc',$claimed->claimToken,StepStatus::COMPLETED,'documents:invalid')),
        'forged Documents receipt');
    $finished=$store->checkpoint(new WorkflowStepCheckpoint(
        $cmd(3),'doc',$claimed->claimToken,StepStatus::COMPLETED,$reference));
    $assert($finished->state==='completed' && $finished->resultReference===$reference,
       'Verified Documents receipt did not checkpoint canonical Step');
    $store->compareAndTransition(new WorkflowRunTransition(
       $cmd(4),WorkflowStatus::RUNNING,WorkflowStatus::COMPLETED,'independent fact attested'));
    $assert($store->get($id)?->status===WorkflowStatus::COMPLETED,'non-Action Run did not complete');
    $assert((int)$db->fetchOne(
       'SELECT COUNT(*) FROM cos_actions WHERE organization_id=:org AND idempotency_key LIKE :key',
       ['org'=>$org,'key'=>'wf:%'])===0,'non-Action path created a canonical Action');
    $assert((int)$db->fetchOne(
       'SELECT COUNT(*) FROM cos_federation_runs WHERE organization_id=:org',['org'=>$org])===0,
       'non-Action execution wrote a legacy Run');
    echo "Federation non-Action MySQL PASS: pinned Plan, exclusive Step lease, late Documents fact, verified receipt, canonical finalization, no Action or legacy execution.\n";
} finally {
    foreach(['cos_workflow_steps','cos_workflow_runs','cos_document_signatures',
       'cos_documents','cos_approvals','cos_policy_evaluations','cos_actions',
       'cos_federation_plans','cos_federation_goal_specs','cos_federation_goals'] as $table){
       $db->executeStatement("DELETE FROM $table WHERE organization_id=:org",['org'=>$org]);
    }
}
