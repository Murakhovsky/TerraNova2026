<?php
declare(strict_types=1);

/**
 * Real historical pristine Federation Run promotion; immutable Plan and zero
 * Action replay, canonical Store writes only, strict shadow parity.
 */
require (string)(getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use App\Persistence\Federation\FederationPlanApprovalEvidenceReader;
use App\Persistence\Federation\FederationHistoricalRunCutover;
use App\Persistence\Federation\FederationMigrationOperatorAuthorization;
use App\Persistence\Federation\FederationPostCutoverIntegrity;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunTransition;
use Kernel\Workflow\Model\WorkflowStatus;
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
$org='wf-migrate-'.$token;
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
$operatorId='';
$privateKey=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
if($privateKey===false)throw new RuntimeException('CI fixture RSA signing key generation failed.');
$publicKey=openssl_pkey_get_details($privateKey)['key']??null;
if(!is_string($publicKey))throw new RuntimeException('CI fixture public key missing.');
$oldAuthority=getenv('COS_FEDERATION_MIGRATION_AUTHORITY_PUBLIC_KEY_BASE64');
$oldMigration=getenv('COS_FEDERATION_HISTORICAL_MIGRATION');
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
    // Real active tenant administrator is the signer-bound operator; no
    // arbitrary CLI string or plan-owner impersonation can authorize apply.
    $db->executeStatement(
        'INSERT INTO cos_organizations (id,name,status) VALUES (:org,:name,"ACTIVE")',
        ['org'=>$org,'name'=>'Federation migration safety fixture']);
    $db->executeStatement(
        'INSERT INTO tn_users
         (organization_id,email,password_hash,full_name,role,status)
         VALUES (:org,:email,:hash,"Migration Safety Operator","admin","active")',
        ['org'=>$org,'email'=>'migration-'.$token.'@example.test',
          'hash'=>password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);
    $operatorId=(string)$db->fetchOne('SELECT LAST_INSERT_ID()');
    $db->executeStatement(
        'INSERT INTO cos_organization_memberships
         (organization_id,user_id,role,status) VALUES (:org,:id,"admin","ACTIVE")',
        ['org'=>$org,'id'=>$operatorId]);
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
    $cutover=new FederationHistoricalRunCutover(
        $db,$store,$shadow,$legacy,
        new FederationMigrationOperatorAuthorization($db),
        new FederationPostCutoverIntegrity($db,$adapter,
            new DoctrineWorkflowActionReceiptVerifier($db,$adapter),
            new FailClosedWorkflowNonActionReceiptEvidence()),
    );
    $before=$cutover->inspect($org,$runId);
    if (!$before['promotable'] || $before['parity']!=='no_target') {
        throw new RuntimeException('Pristine historical Federation Run was not eligible.');
    }
    $reject=static function(callable $action,string $reason):void{
        try{$action();}catch(Throwable){return;}
        throw new RuntimeException('Unsafe historical cutover accepted: '.$reason);
    };
    $expiry=time()+600;
    $signedPayload=implode("\n",['federation-cutover-v2',$org,$runId,
        $operatorId,(string)$before['source_sha256'],(string)$expiry]);
    if(!openssl_sign($signedPayload,$signature,$privateKey,OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('CI fixture signing failed.');
    }
    $permit='v2:'.$expiry.':'.bin2hex($signature);
    $reject(static fn()=> $cutover->promotePristine($org,$runId,$operatorId,$permit),
        'apply without explicit operator rollout gate');
    putenv('COS_FEDERATION_HISTORICAL_MIGRATION=explicit_operator_approved');
    putenv('COS_FEDERATION_MIGRATION_AUTHORITY_PUBLIC_KEY_BASE64='.base64_encode($publicKey));
    $reject(static fn()=> $cutover->promotePristine($org,$runId,$operatorId,'v2:'.$expiry.':'.str_repeat('0',512)),
        'unauthenticated migration permit');
    $reject(static fn()=> $cutover->promotePristine($org,$runId,'999999999',$permit),
        'arbitrary operator without tenant membership');
    $reject(static fn()=> $cutover->promotePristine($org,$runId,$operatorId,
        'v2:'.(time()-1).':'.str_repeat('0',512)), 'expired signed permit');
    $db->executeStatement(
        'UPDATE cos_organization_memberships SET status="SUSPENDED"
         WHERE organization_id=:org AND user_id=:id',
        ['org'=>$org,'id'=>$operatorId]);
    $reject(static fn()=> $cutover->promotePristine($org,$runId,$operatorId,$permit),
        'suspended tenant operator');
    $db->executeStatement(
        'UPDATE cos_organization_memberships SET status="ACTIVE"
         WHERE organization_id=:org AND user_id=:id',
        ['org'=>$org,'id'=>$operatorId]);
    $result=$cutover->promotePristine($org,$runId,$operatorId,$permit);
    if ($result['state']!=='promoted' || $result['parity']!=='match'
        || $shadow->federation($org,$runId)->status->value!=='match') {
        throw new RuntimeException('No-replay historical promotion did not prove exact parity.');
    }
    $read=$cutover->inspect($org,$runId);
    if ($read['migration_state']!=='promoted' || $read['parity']!=='match'
        || $read['cutover_parity']!=='match'
        || ($read['post_cutover_integrity']['status']??null)!=='healthy') {
        throw new RuntimeException('Historical immutable migration ledger missing.');
    }
    $reject(static fn()=> $cutover->promotePristine($org,$runId,$operatorId,$permit),
        'duplicate historical promotion');
    $audit=(int)$db->fetchOne(
        'SELECT COUNT(*) FROM cos_federation_migration_audit
         WHERE organization_id=:org AND legacy_run_id=:run AND event_code=:event',
        ['org'=>$org,'run'=>$runId,'event'=>'cutover_accepted']);
    if($audit!==1)throw new RuntimeException('One-time authorization audit not persisted.');
    // After cutover the canonical authority progresses while frozen legacy
    // state remains pending: NOT a parity drift.
    $store->compareAndTransition(new WorkflowRunTransition(
        new WorkflowRunCommand($runIdentity,$actor,1,'federation:'.$runId),
        WorkflowStatus::CREATED,WorkflowStatus::RUNNING,'post-cutover normal progress'));
    $progress=$cutover->inspect($org,$runId);
    if (($progress['cutover_parity']??null)!=='match'
        || ($progress['post_cutover_integrity']['status']??null)!=='healthy'
        || ($progress['post_cutover_integrity']['canonical_state']??null)!=='running') {
        throw new RuntimeException('Normal canonical progress incorrectly marked as post-cutover drift.');
    }
    // An Action reference flag alone is NOT a receipt. Even after the
    // canonical Run advances, a forged completed Step cannot pass integrity
    // without first-attempt Action/Policy/Approval evidence. No re-dispatch.
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET state="completed",attempts=1,
         result_reference=:fake WHERE organization_id=:org
         AND run_id=:run AND step_id=:step',
        ['fake'=>'action:'.str_repeat('0',32),'org'=>$org,'run'=>$runId,'step'=>'publish']);
    $forged=$cutover->inspect($org,$runId);
    if (($forged['cutover_parity']??null)!=='match'
        || ($forged['post_cutover_integrity']['status']??null)!=='reconciliation_required'
        || !in_array('completed_receipt_requires_reconciliation:publish',
            $forged['post_cutover_integrity']['findings']??[],true)) {
        throw new RuntimeException('Forged canonical receipt bypassed independent reconciliation.');
    }
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET state="pending",attempts=0,
         result_reference=NULL WHERE organization_id=:org
         AND run_id=:run AND step_id=:step',
        ['org'=>$org,'run'=>$runId,'step'=>'publish']);
    $afterForged=$cutover->inspect($org,$runId);
    if (($afterForged['post_cutover_integrity']['status']??null)!=='healthy') {
        throw new RuntimeException('Canonical Step restoration unexpectedly changed immutable baseline.');
    }
    // Once promoted, historical Run/Step rows are pinned to their exact
    // pre-cutover checksum and can never become silent new authority.
    $db->executeStatement(
        'UPDATE cos_federation_steps SET attempts=1
         WHERE organization_id=:org AND run_id=:run AND step_id=:step',
        ['org'=>$org,'run'=>$runId,'step'=>'publish'],
    );
    $mutated=$cutover->inspect($org,$runId);
    if (!in_array('legacy_source_mutated_after_cutover',$mutated['reasons'],true)
        || ($mutated['cutover_parity']??null)!=='match'
        || ($mutated['post_cutover_integrity']['status']??null)!=='drift') {
        throw new RuntimeException('Changed historical Step bypassed immutable source checksum.');
    }
    $db->executeStatement(
        'UPDATE cos_federation_steps SET attempts=0
         WHERE organization_id=:org AND run_id=:run AND step_id=:step',
        ['org'=>$org,'run'=>$runId,'step'=>'publish'],
    );
    $db->executeStatement(
        'UPDATE cos_workflow_runs SET correlation_id=:tampered
         WHERE organization_id=:org AND run_id=:run',
        ['tampered'=>'forged-'.$token,'org'=>$org,'run'=>$runId]);
    $changed=$cutover->inspect($org,$runId);
    if (($changed['post_cutover_integrity']['status']??null)!=='drift'
        || !in_array('canonical_immutable_identity_changed',
            $changed['post_cutover_integrity']['findings']??[],true)) {
        throw new RuntimeException('Canonical immutable baseline drift not detected.');
    }
    $db->executeStatement(
        'UPDATE cos_workflow_runs SET correlation_id=:original
         WHERE organization_id=:org AND run_id=:run',
        ['original'=>'federation:'.$runId,'org'=>$org,'run'=>$runId]);
    $actions=(int)$db->fetchOne(
        'SELECT COUNT(*) FROM cos_actions WHERE organization_id=:org AND idempotency_key LIKE :key',
        ['org'=>$org,'key'=>'fed:%'],
    );
    if($actions!==0)throw new RuntimeException('Promotion replayed a legacy Action.');
    $rows=$db->fetchAllAssociative(
        'SELECT state,attempts,idempotency_key FROM cos_workflow_steps
         WHERE organization_id=:org AND run_id=:run',
        ['org'=>$org,'run'=>$runId],
    );
    if(count($rows)!==1 || $rows[0]['state']!=='pending' || (int)$rows[0]['attempts']!==0
        || $rows[0]['idempotency_key']!==$stepKey) {
        throw new RuntimeException('Canonical Run was not a pristine one-to-one technical projection.');
    }
    // Uncertain historical Actions cannot be declared migratable just by
    // copying an approved Plan, even if their Step has no saved receipt.
    $blocked='blocked-'.$token;
    $blockedPlan='blocked-plan-'.$token;
    $blockedGoal='blocked-goal-'.$token;
    $db->executeStatement(
        'INSERT INTO cos_federation_goals
         (organization_id,goal_id,owner_id,current_spec_version,state,revision,created_at,updated_at)
         VALUES (:org,:goal,:owner,1,"draft",1,NOW(6),NOW(6))',
        ['org'=>$org,'goal'=>$blockedGoal,'owner'=>$owner],
    );
    $db->executeStatement(
        'INSERT INTO cos_federation_plans
         (organization_id,plan_id,goal_id,spec_version,plan_version,state,plan_json,created_at)
         VALUES (:org,:plan,:goal,1,1,"approved",:json,NOW(6))',
        ['org'=>$org,'plan'=>$blockedPlan,'goal'=>$blockedGoal,
         'json'=>json_encode(['steps'=>$sourceSteps],JSON_THROW_ON_ERROR)],
    );
    $db->executeStatement(
        'INSERT INTO cos_federation_runs
         (organization_id,run_id,goal_id,plan_id,state,revision,created_at,updated_at)
         VALUES (:org,:run,:goal,:plan,"running",2,NOW(6),NOW(6))',
        ['org'=>$org,'run'=>$blocked,'goal'=>$blockedGoal,'plan'=>$blockedPlan],
    );
    $blockedKey=hash('sha256',$org."\0".$blocked."\0publish");
    $db->executeStatement(
        'INSERT INTO cos_federation_steps
         (organization_id,run_id,step_id,capability_id,capability_version,
          side_effect_level,state,attempts,idempotency_key,updated_at)
         VALUES (:org,:run,"publish","sales.create_task","1.0.0",
                 "external","claimed",1,:key,NOW(6))',
        ['org'=>$org,'run'=>$blocked,'key'=>$blockedKey],
    );
    $examination=$cutover->inspect($org,$blocked);
    if (($examination['step_reconciliation'][0]['diagnosis']??null)!=='manual_reconciliation_required'
        || ($examination['step_reconciliation'][0]['legacy_attempts']??null)!==1) {
        throw new RuntimeException('Historical claimed Action missing operator reconciliation diagnosis.');
    }
    if($examination['promotable'] || !in_array('step_executed_claimed_or_changed',
        $examination['reasons'],true)) {
        throw new RuntimeException('Previously attempted Action was offered unsafe promotion.');
    }
    $reject(static fn()=> $cutover->promotePristine($org,$blocked,$operatorId,$permit),
        'previously claimed Action could replay');
    $mixed='mixed-'.$token;
    $mixedPlan='mixed-plan-'.$token;
    $db->executeStatement(
        'INSERT INTO cos_federation_plans
         (organization_id,plan_id,goal_id,spec_version,plan_version,state,plan_json,created_at)
         VALUES (:org,:plan,:goal,1,1,"approved",:json,NOW(6))',
        ['org'=>$org,'plan'=>$mixedPlan,'goal'=>$goalId,
         'json'=>json_encode(['steps'=>$sourceSteps],JSON_THROW_ON_ERROR)],
    );
    $db->executeStatement(
        'INSERT INTO cos_federation_runs
         (organization_id,run_id,goal_id,plan_id,state,revision,created_at,updated_at)
         VALUES (:org,:run,:goal,:plan,"pending",1,NOW(6),NOW(6))',
        ['org'=>$org,'run'=>$mixed,'goal'=>$blockedGoal,'plan'=>$mixedPlan],
    );
    $reject(static fn()=> $cutover->inspect($org,$mixed),
        'legacy Run points to a Plan owned by a different Goal');
    $other=$shadow->federation('other-tenant',$runId);
    if($other->status->value!=='no_target') {
        throw new RuntimeException('Historical shadow leaked tenant information.');
    }
    echo "Federation historical canonical cutover MySQL passed: atomic Store migration, shadow MATCH, immutable ledger, no Action replay, claimed legacy fenced, tenant isolation.\n";
} finally {
    if ($oldMigration===false)putenv('COS_FEDERATION_HISTORICAL_MIGRATION');
    else putenv('COS_FEDERATION_HISTORICAL_MIGRATION='.$oldMigration);
    if ($oldAuthority===false)putenv('COS_FEDERATION_MIGRATION_AUTHORITY_PUBLIC_KEY_BASE64');
    else putenv('COS_FEDERATION_MIGRATION_AUTHORITY_PUBLIC_KEY_BASE64='.$oldAuthority);
    foreach(['cos_federation_migration_audit','cos_federation_execution_migrations',
        'cos_workflow_steps','cos_workflow_runs',
        'cos_federation_steps','cos_federation_runs','cos_approvals','cos_policy_evaluations',
        'cos_action_attempts','cos_actions','cos_federation_plans',
        'cos_federation_goal_specs','cos_federation_goals',
        'cos_organization_memberships','tn_users'] as $table) {
        $db->executeStatement("DELETE FROM $table WHERE organization_id=:org",['org'=>$org]);
    }
    $db->executeStatement('DELETE FROM cos_organizations WHERE id=:org',['org'=>$org]);
}
