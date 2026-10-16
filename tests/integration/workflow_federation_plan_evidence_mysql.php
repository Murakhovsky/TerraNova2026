<?php
declare(strict_types=1);

/**
 * Controlled MySQL proof of the real Federation Plan approval read-only adapter.
 * No external Action dispatch, no migration, no second technical writer.
 */
require (string)(getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use App\Persistence\Federation\FederationPlanApprovalEvidenceReader;
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\DoctrineWorkflowShadowParityReader;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use App\Persistence\Workflow\FailClosedWorkflowNonActionReceiptEvidence;
use Platform\Workflow\Service\WorkflowShadowParityComparator;
use App\Persistence\Workflow\FederationWorkflowPlanApprovalEvidence;
use App\Persistence\Workflow\FailClosedWorkflowReceiptVerifier;
use Doctrine\DBAL\DriverManager;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Workflow\Contract\WorkflowStepClaimAdmissionInterface;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Model\WorkflowStepSnapshot;

$db=DriverManager::getConnection([
    'driver'=>'pdo_mysql','host'=>(string)(getenv('DB_HOST')?:'mysql'),
    'port'=>(int)(getenv('DB_PORT')?:3306),'dbname'=>(string)(getenv('DB_DATABASE')?:'cos'),
    'user'=>(string)(getenv('DB_USERNAME')?:'cos'),
    'password'=>(string)(getenv('DB_PASSWORD')?:''),'charset'=>'utf8mb4',
]);
$token=bin2hex(random_bytes(6));
$org='wf-fed-'.$token;
$owner='fed-owner-'.$token;
$actor=new TenantContext(
    UserId::fromString($owner),OrganizationId::fromString($org),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::MANAGE)],
);
$goalId='goal-'.$token;
$planId='plan-'.$token;
$runId='run-'.$token;
$approvalId=bin2hex(random_bytes(16));
$independent='fed-approver-'.$token;
$keys=['org'=>$org,'plan'=>$planId];
$sourceSteps=[
    ['id'=>'publish','capability_id'=>'sales.create_task','capability_version'=>'1.0.0',
     'side_effect_level'=>'external','depends_on'=>[],
     'execution_binding'=>'action:sales.create_task',
     'input'=>['target_type'=>'client_case','target_id'=>'123','parameters'=>['note'=>'planned']]],
];
$definition=new WorkflowDefinitionSnapshot('federation-approved-plan','1.0.0',$sourceSteps);
$legacy=new FederationPlanApprovalEvidenceReader($db);
$adapter=new FederationWorkflowPlanApprovalEvidence($db,$legacy);
$store=new DoctrineWorkflowExecutionStore(
    $db,new FailClosedWorkflowReceiptVerifier(),
    new class implements WorkflowStepClaimAdmissionInterface {
        public function assertAuthorized(WorkflowStepClaim $claim, WorkflowStepSnapshot $step):void {
            throw new DomainException('No external actions run during plan evidence verification.');
        }
    },
);
$deny=static function(callable $action,string $why):void {
    try {$action();} catch(Throwable) {return;}
    throw new RuntimeException('Unsafe Federation Plan proof accepted: '.$why);
};
try {
    $db->executeStatement(
        'INSERT INTO cos_federation_goals
           (organization_id,goal_id,owner_id,current_spec_version,state,revision,created_at,updated_at)
         VALUES (:org,:goal,:owner,1,"draft",1,NOW(6),NOW(6))',
        ['org'=>$org,'goal'=>$goalId,'owner'=>$owner],
    );
    $db->executeStatement(
        'INSERT INTO cos_federation_plans
           (organization_id,plan_id,goal_id,spec_version,plan_version,state,plan_json,created_at)
         VALUES (:org,:plan,:goal,1,1,"approved",:json,NOW(6))',
        ['org'=>$org,'plan'=>$planId,'goal'=>$goalId,
         'json'=>json_encode(['steps'=>$sourceSteps,'created_by'=>$owner],JSON_THROW_ON_ERROR)],
    );
    $stored=(string)$db->fetchOne(
        'SELECT plan_json FROM cos_federation_plans
         WHERE organization_id=:org AND plan_id=:plan',
        $keys,
    );
    if($stored==='')throw new RuntimeException('Federation fixture Plan JSON missing.');
    $digest=hash('sha256',$stored);

    $db->executeStatement(
        'INSERT INTO cos_actions
           (id,organization_id,type,target_type,target_id,parameters,
            source_type,source_id,status,execution_mode,risk_level,idempotency_key,correlation_id,executed_at)
         VALUES (:id,:org,"cos.federation.plan.approval","cos_federation_plan",:target,:params,
                 "USER",:owner,"COMPLETED","APPROVAL_REQUIRED","HIGH",:dedup,:correlation,NOW(6))',
        ['id'=>$approvalId,'org'=>$org,'target'=>$planId,'owner'=>$owner,
         'params'=>json_encode([
            'goal_id'=>$goalId,'plan_id'=>$planId,
            'specification_version'=>1,'plan_hash'=>$digest],JSON_THROW_ON_ERROR),
         'dedup'=>'wf:approved-plan:'.$token,'correlation'=>'plan-'.$token],
    );
    $db->executeStatement(
        'INSERT INTO cos_policy_evaluations
           (id,organization_id,action_id,decision,correlation_id,evaluated_at)
         VALUES (:id,:org,:action,"APPROVAL_REQUIRED",:correlation,NOW(6))',
        ['id'=>bin2hex(random_bytes(16)),'org'=>$org,
         'action'=>$approvalId,'correlation'=>'plan-'.$token],
    );
    $db->executeStatement(
        'INSERT INTO cos_approvals
           (id,organization_id,action_id,status,approver_type,approver_id,
            requested_by_type,requested_by_id,decided_by_type,decided_by_id,
            decided_at,expires_at)
         VALUES (:id,:org,:action,"APPROVED","USER",:approver,"USER",:owner,
                 "USER",:approver,NOW(6),DATE_ADD(NOW(6),INTERVAL 1 DAY))',
        ['id'=>bin2hex(random_bytes(16)),'org'=>$org,'action'=>$approvalId,
         'approver'=>$independent,'owner'=>$owner],
    );
    $runIdentity=new WorkflowRunIdentity($org,$runId);
    $store->create(new WorkflowRunDraft(
        $runIdentity,$definition->id,$definition->version,hash('sha256','fed-input'),
        $owner,'fed-proof-'.$token,$planId,$approvalId,$digest,$definition,
    ),$actor);
    $adapter->assertTrusted($runIdentity,$planId,$digest,$approvalId);

    // Real legacy Federation source + canonical Run; operator shadow reads SQL,
    // not a pre-built comparator array. Unknown receipt never becomes MATCH.
    $db->executeStatement(
        'INSERT INTO cos_federation_runs
         (organization_id,run_id,goal_id,plan_id,state,revision,created_at,updated_at)
         VALUES (:org,:run,:goal,:plan,"pending",1,NOW(6),NOW(6))',
        ['org'=>$org,'run'=>$runId,'goal'=>$goalId,'plan'=>$planId],
    );
    $stepKey=hash('sha256',$org."\0".$runId."\0publish");
    $db->executeStatement(
        'INSERT INTO cos_federation_steps
         (organization_id,run_id,step_id,capability_id,capability_version,
          side_effect_level,state,attempts,idempotency_key,updated_at)
         VALUES (:org,:run,"publish","sales.create_task","1.0.0",
                 "external","pending",0,:key,NOW(6))',
        ['org'=>$org,'run'=>$runId,'key'=>$stepKey],
    );
    $shadow=new DoctrineWorkflowShadowParityReader(
        $db,$store,new WorkflowShadowParityComparator(),$adapter,
        new DoctrineWorkflowActionReceiptVerifier($db,$adapter),
        new FailClosedWorkflowNonActionReceiptEvidence(),
    );
    if($shadow->federation($org,$runId)->status->value!=='match') {
        throw new RuntimeException('Real approved Federation shadow should match initial canonical Run.');
    }
    $db->executeStatement(
        'UPDATE cos_federation_steps SET attempts=1
         WHERE organization_id=:org AND run_id=:run AND step_id="publish"',
        ['org'=>$org,'run'=>$runId],
    );
    if($shadow->federation($org,$runId)->status->value!=='drift') {
        throw new RuntimeException('Federation SQL shadow ignored legacy execution drift.');
    }
    $db->executeStatement(
        'UPDATE cos_federation_steps SET attempts=0
         WHERE organization_id=:org AND run_id=:run AND step_id="publish"',
        ['org'=>$org,'run'=>$runId],
    );
    $db->executeStatement(
        'UPDATE cos_approvals SET decided_by_id=:self WHERE organization_id=:org AND action_id=:id',
        ['self'=>$owner,'org'=>$org,'id'=>$approvalId],
    );
    if($shadow->federation($org,$runId)->status->value!=='partial') {
        throw new RuntimeException('Revoked human approval cannot yield full Federation shadow MATCH.');
    }
    $db->executeStatement(
        'UPDATE cos_approvals SET decided_by_id=:approver WHERE organization_id=:org AND action_id=:id',
        ['approver'=>$independent,'org'=>$org,'id'=>$approvalId],
    );
    if($shadow->federation('other-tenant',$runId)->status->value!=='no_target') {
        throw new RuntimeException('Federation shadow leaked another tenant.');
    }
    echo "Real Federation SQL shadow passed: initial MATCH, execution DRIFT, untrusted approval PARTIAL and tenant isolation.\n";

    $deny(static fn()=> $adapter->assertTrusted($runIdentity,$planId,hash('sha256','forged'),$approvalId),
        'incorrect Plan snapshot digest');
    $deny(static fn()=> $adapter->assertTrusted(
        new WorkflowRunIdentity('other-tenant',$runId),$planId,$digest,$approvalId),
        'foreign tenant Plan');
    $deny(static fn()=> $adapter->assertTrusted($runIdentity,$planId,$digest,bin2hex(random_bytes(16))),
        'forged approval Action reference');
    $db->executeStatement(
        'UPDATE cos_approvals SET decided_by_id=:self WHERE organization_id=:org AND action_id=:id',
        ['self'=>$owner,'org'=>$org,'id'=>$approvalId],
    );
    $deny(static fn()=> $adapter->assertTrusted($runIdentity,$planId,$digest,$approvalId),'self approval');
    $db->executeStatement(
        'UPDATE cos_approvals SET decided_by_id=:other WHERE organization_id=:org AND action_id=:id',
        ['other'=>$independent,'org'=>$org,'id'=>$approvalId],
    );
    $db->executeStatement(
        'UPDATE cos_policy_evaluations SET decision="DENIED"
         WHERE organization_id=:org AND action_id=:id',
        ['org'=>$org,'id'=>$approvalId],
    );
    $deny(static fn()=> $adapter->assertTrusted($runIdentity,$planId,$digest,$approvalId),
        'revoked plan approval Policy');
    $db->executeStatement(
        'UPDATE cos_policy_evaluations SET decision="APPROVAL_REQUIRED"
         WHERE organization_id=:org AND action_id=:id',
        ['org'=>$org,'id'=>$approvalId],
    );
    $newSteps=$sourceSteps;
    $newSteps[0]['input']['parameters']['note']='injected';
    $db->executeStatement(
        'UPDATE cos_workflow_runs SET definition_json=:modified
         WHERE organization_id=:org AND run_id=:run',
        ['modified'=>json_encode($newSteps,JSON_THROW_ON_ERROR),'org'=>$org,'run'=>$runId],
    );
    $deny(static fn()=> $adapter->assertTrusted($runIdentity,$planId,$digest,$approvalId),
        'canonical Run definition drift from approved Plan');
    $db->executeStatement(
        'UPDATE cos_workflow_runs SET definition_json=:original
         WHERE organization_id=:org AND run_id=:run',
        ['original'=>json_encode($sourceSteps,JSON_THROW_ON_ERROR),'org'=>$org,'run'=>$runId],
    );
    $db->executeStatement(
        'UPDATE cos_federation_plans SET state="proposed"
         WHERE organization_id=:org AND plan_id=:plan',$keys,
    );
    $deny(static fn()=> $adapter->assertTrusted($runIdentity,$planId,$digest,$approvalId),
        'unapproved Federation Plan status');
    $db->executeStatement(
        'UPDATE cos_federation_plans SET state="approved"
         WHERE organization_id=:org AND plan_id=:plan',$keys,
    );
    $adapter->assertTrusted($runIdentity,$planId,$digest,$approvalId);
    echo "Federation approved Plan MySQL adapter passed: real Action/Policy/Approval proof, tenant, specification, immutable graph and fail-closed drift.\n";
} finally {
    $db->executeStatement('DELETE FROM cos_federation_steps WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_federation_runs WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_steps WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_runs WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_approvals WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_policy_evaluations WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_action_attempts WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_actions WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_federation_plans WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_federation_goals WHERE organization_id=:org',['org'=>$org]);
}
