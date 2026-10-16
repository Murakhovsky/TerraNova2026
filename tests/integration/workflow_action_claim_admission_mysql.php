<?php
declare(strict_types=1);

/**
 * No real Action execution. Controlled MySQL pre-claim evidence test against
 * a synthetic queued canonical Action and real module/capability contracts.
 */
require (string) (getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__, 2) . '/symfony/vendor/autoload.php');

use App\Persistence\Workflow\DoctrineWorkflowActionClaimAdmission;
use App\Persistence\Workflow\DoctrineWorkflowActionWorkerAdmission;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use App\Persistence\Workflow\FailClosedWorkflowNonActionReceiptEvidence;
use App\Persistence\Workflow\WorkflowPredecessorReceiptVerifier;
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\FailClosedWorkflowReceiptVerifier;
use App\Persistence\Workflow\FailClosedWorkflowPlanApprovalEvidence;
use App\Persistence\Workflow\FailClosedWorkflowStepClaimAdmission;
use Doctrine\DBAL\DriverManager;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Infrastructure\Platform\Persistence\MySql\Action\MysqlActionRepository;
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
use Platform\Workflow\Model\WorkflowStepClaim;

$db=DriverManager::getConnection([
    'driver'=>'pdo_mysql', 'host'=>(string)(getenv('DB_HOST') ?: 'mysql'),
    'port'=>(int)(getenv('DB_PORT') ?: 3306),
    'dbname'=>(string)(getenv('DB_DATABASE') ?: 'cos'),
    'user'=>(string)(getenv('DB_USERNAME') ?: 'cos'),
    'password'=>(string)(getenv('DB_PASSWORD') ?: ''), 'charset'=>'utf8mb4',
]);
$token=bin2hex(random_bytes(6));
$org='wf-gate-'.$token;
$actorId='gate-manager-'.$token;
$actor=new TenantContext(
    UserId::fromString($actorId), OrganizationId::fromString($org),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::MANAGE)],
);
$identity=new WorkflowRunIdentity($org,'gate-run-'.$token);
$approvedPlan='gate-plan-'.$token;
$planHash=hash('sha256','approved-plan-'.$token);
$approvalRef='plan-approval-'.$token;
$def=new WorkflowDefinitionSnapshot('test.pinned-command','1.0.0',[
    ['id'=>'send','capability_id'=>'test.publish','capability_version'=>'1.0.0',
     'side_effect_level'=>'external','owner_domain'=>'sales',
     'execution_binding'=>'action:test.publish','depends_on'=>[],
     'input'=>['target_type'=>'case','target_id'=>'123','parameters'=>['approved'=>true,'count'=>2]]],
]);
$draft=new WorkflowRunDraft(
    $identity,$def->id,$def->version,hash('sha256','claim-input-'.$token),
    $actorId,'corr-'.$token,$approvedPlan,$approvalRef,$planHash,$def,
);
$permissionState=new class implements ModuleStateRepositoryInterface {
    /** @var array<string,bool> */
    public array $changes=[];
    public function enabledOverride(string $organizationId,string $moduleId):?bool {
        return $this->changes[$organizationId.':'.$moduleId] ?? null;
    }
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void {
        $this->changes[$organizationId.':'.$moduleId]=$enabled;
    }
};
$capability=new CapabilityContract(
    id:'test.publish',version:'1.0.0',ownerDomain:'sales',kind:'command',
    inputSchema:'resources/contracts/tests/claim.schema.json',
    outputSchema:'resources/contracts/tests/claim-output.schema.json',
    executionBinding:'action:test.publish',sideEffectLevel:'external',
    permission:'test.publish',approvalPolicy:'required',idempotency:'required',
    timeoutPolicy:['seconds'=>60],retryPolicy:['max_attempts'=>1],
    failureContract:'manual_reconciliation',
);
$catalog=new ModuleCatalog([
    new ModuleDefinition(
        new ModuleManifest('sales','Sales fixture','1.0.0'),
        new ModuleContributions(capabilities:['test.publish'],capabilityContracts:[$capability]),
    ),
]);
$modules=new ActiveModuleResolver($catalog,$permissionState);
$knownPlan=new class($identity,$approvedPlan,$planHash,$approvalRef) implements WorkflowPlanApprovalEvidenceInterface {
    public function __construct(
        private WorkflowRunIdentity $identity,
        private string $plan,
        private string $digest,
        private string $approval,
    ) {}
    public function assertTrusted(
        WorkflowRunIdentity $identity,string $planId,string $planDigest,string $approvalReference,
    ):void {
        if($identity!=$this->identity || $planId!==$this->plan
            || $planDigest!==$this->digest || $approvalReference!==$this->approval) {
            throw new DomainException('No trusted approved Plan proof.');
        }
    }
};
$admission=new DoctrineWorkflowActionClaimAdmission(
    $db,$knownPlan,new CanonicalCapabilityCatalog($catalog),$modules,
);
$untrusted=new DoctrineWorkflowActionClaimAdmission(
    $db,new FailClosedWorkflowPlanApprovalEvidence(),new CanonicalCapabilityCatalog($catalog),$modules,
);
$store=new DoctrineWorkflowExecutionStore($db,new FailClosedWorkflowReceiptVerifier(),new FailClosedWorkflowStepClaimAdmission());
$activeStore=new DoctrineWorkflowExecutionStore($db,new FailClosedWorkflowReceiptVerifier(),$admission);
$reject=static function(callable $fn,string $reason):void {
    try {$fn();} catch(Throwable) {return;}
    throw new RuntimeException('Workflow Action admission accepted unsafe evidence: '.$reason);
};
$actionId=bin2hex(random_bytes(16));
$keys=['org'=>$org,'action'=>$actionId];
try {
    $store->create($draft,$actor);
    $store->compareAndTransition(new WorkflowRunTransition(
        new WorkflowRunCommand($identity,$actor,1,$draft->correlationId),
        WorkflowStatus::CREATED,WorkflowStatus::RUNNING,'CI synthetic Action claim',
    ));
    $claim=new WorkflowStepClaim(
        new WorkflowRunCommand($identity,$actor,2,$draft->correlationId),'send','fixture-worker',90,
    );
    $step=$store->steps($identity)[0];
    $reject(static fn()=> $admission->assertAuthorized($claim,$step),'missing canonical Action');
    $db->executeStatement(
        'INSERT INTO cos_actions
         (id,organization_id,type,target_type,target_id,parameters,source_type,source_id,
          status,execution_mode,risk_level,idempotency_key,correlation_id)
         VALUES (:id,:org,"test.publish","case","123",:parameters,
                 "USER",:actor,"QUEUED","APPROVAL_REQUIRED","HIGH",:key,:correlation)',
        ['id'=>$actionId,'org'=>$org,
         'parameters'=>json_encode(['count'=>2,'approved'=>true],JSON_THROW_ON_ERROR),
         'actor'=>$actorId,'key'=>'wf:'.$step->idempotencyKey,'correlation'=>$draft->correlationId],
    );
    $db->executeStatement(
        'INSERT INTO cos_policy_evaluations
         (id,organization_id,action_id,decision,correlation_id,evaluated_at)
         VALUES (:id,:org,:action,"APPROVAL_REQUIRED",:correlation,NOW(6))',
        ['id'=>bin2hex(random_bytes(16)),...$keys,'correlation'=>$draft->correlationId],
    );
    $approvedBy='gate-approver-'.$token;
    $db->executeStatement(
        'INSERT INTO cos_approvals
         (id,organization_id,action_id,status,approver_type,approver_id,
          requested_by_type,requested_by_id,decided_by_type,decided_by_id,decided_at,expires_at)
         VALUES (:id,:org,:action,"APPROVED","USER",:approver,
                 "USER",:owner,"USER",:approver,NOW(6),DATE_ADD(NOW(6),INTERVAL 1 DAY))',
        ['id'=>bin2hex(random_bytes(16)),...$keys,'approver'=>$approvedBy,'owner'=>$actorId],
    );
    // Claim BEFORE independent human approval so that a queued worker can
    // never execute without the exclusive persisted Workflow Step lease.
    $db->executeStatement(
        'UPDATE cos_actions SET status="PENDING_APPROVAL" WHERE organization_id=:org AND id=:action',
        $keys,
    );
    $db->executeStatement(
        'UPDATE cos_approvals SET status="PENDING",decided_by_type=NULL,decided_by_id=NULL,
             decided_at=NULL WHERE organization_id=:org AND action_id=:action',
        $keys,
    );
    $admission->assertAuthorized($claim,$step);
    $reject(static fn()=> $untrusted->assertAuthorized($claim,$step),'untrusted Plan while approval pending');
    $db->executeStatement(
        'UPDATE cos_actions SET status="QUEUED" WHERE organization_id=:org AND id=:action',
        $keys,
    );
    $db->executeStatement(
        'UPDATE cos_approvals SET status="APPROVED",decided_by_type="USER",
             decided_by_id=:approver,decided_at=NOW(6)
         WHERE organization_id=:org AND action_id=:action',
        ['approver'=>$approvedBy,...$keys],
    );
    $admission->assertAuthorized($claim,$step);
    $reject(static fn()=> $untrusted->assertAuthorized($claim,$step),'untrusted Plan gate');
    $permissionState->setEnabled($org,'sales',false);
    $reject(static fn()=> $admission->assertAuthorized($claim,$step),'disabled owner module');
    $permissionState->setEnabled($org,'sales',true);
    $db->executeStatement(
        'UPDATE cos_approvals SET decided_by_id=:actor WHERE organization_id=:org AND action_id=:action',
        ['actor'=>$actorId,...$keys],
    );
    $reject(static fn()=> $admission->assertAuthorized($claim,$step),'self approval');
    $db->executeStatement(
        'UPDATE cos_approvals SET decided_by_id=:actor WHERE organization_id=:org AND action_id=:action',
        ['actor'=>$approvedBy,...$keys],
    );
    $db->executeStatement(
        'UPDATE cos_policy_evaluations SET decision="DENIED" WHERE organization_id=:org AND action_id=:action',
        $keys,
    );
    $reject(static fn()=> $admission->assertAuthorized($claim,$step),'latest Policy denial');
    $db->executeStatement(
        'UPDATE cos_policy_evaluations SET decision="APPROVAL_REQUIRED" WHERE organization_id=:org AND action_id=:action',
        $keys,
    );
    $db->executeStatement(
        'UPDATE cos_actions SET parameters=:params WHERE organization_id=:org AND id=:action',
        ['params'=>json_encode(['count'=>2,'approved'=>false],JSON_THROW_ON_ERROR),...$keys],
    );
    $reject(static fn()=> $admission->assertAuthorized($claim,$step),'forged parameters');
    $db->executeStatement(
        'UPDATE cos_actions SET parameters=:params WHERE organization_id=:org AND id=:action',
        ['params'=>json_encode(['count'=>2,'approved'=>true],JSON_THROW_ON_ERROR),...$keys],
    );
    $db->executeStatement(
        'INSERT INTO cos_action_attempts (organization_id,action_id,attempt,status,started_at)
         VALUES (:org,:action,1,"RUNNING",NOW(6))',
        $keys,
    );
    $reject(static fn()=> $admission->assertAuthorized($claim,$step),'prior worker attempt');
    $db->executeStatement(
        'DELETE FROM cos_action_attempts WHERE organization_id=:org AND action_id=:action',
        $keys,
    );
    $db->executeStatement(
        'UPDATE cos_actions SET status="RUNNING" WHERE organization_id=:org AND id=:action',
        $keys,
    );
    $reject(static fn()=> $admission->assertAuthorized($claim,$step),'already started Action');
    $db->executeStatement(
        'UPDATE cos_actions SET status="QUEUED" WHERE organization_id=:org AND id=:action',
        $keys,
    );
    $admission->assertAuthorized($claim,$step);
    $claimed=$activeStore->claim($claim);
    if($claimed->state!=='claimed' || $claimed->claimToken===null
        || $store->get($identity)?->revision!==3) {
        throw new RuntimeException('Existing canonical Action admission did not fence shared Step claim.');
    }
    $reject(static fn()=> $activeStore->claim($claim),'duplicate external claim');
    if($db->fetchOne('SELECT status FROM cos_actions WHERE organization_id=:org AND id=:action',$keys)!=='QUEUED') {
        throw new RuntimeException('Admission unexpectedly mutated or dispatched the canonical Action.');
    }

    // The worker runs after the canonical Action repository records attempt 1.
    $db->executeStatement(
        'UPDATE cos_actions SET status="RUNNING",started_at=NOW(6)
         WHERE organization_id=:org AND id=:action', $keys,
    );
    $db->executeStatement(
        'INSERT INTO cos_action_attempts
         (organization_id,action_id,attempt,worker_id,status,started_at)
         VALUES (:org,:action,1,"action-worker","RUNNING",NOW(6))', $keys,
    );
    $predecessors = new WorkflowPredecessorReceiptVerifier(
        new DoctrineWorkflowActionReceiptVerifier($db,$knownPlan),
        new FailClosedWorkflowNonActionReceiptEvidence(),
    );
    $worker=new DoctrineWorkflowActionWorkerAdmission(
        $db,$knownPlan,new CanonicalCapabilityCatalog($catalog),$modules,$predecessors,
    );
    $untrustedWorker=new DoctrineWorkflowActionWorkerAdmission(
        $db,new FailClosedWorkflowPlanApprovalEvidence(),new CanonicalCapabilityCatalog($catalog),$modules,$predecessors,
    );
    $action=new Action(
        $actionId,$org,'test.publish','case','123',['approved'=>true,'count'=>2],
        'USER',$actorId,'APPROVAL_REQUIRED','HIGH',
        'wf:'.$step->idempotencyKey,new DateTimeImmutable(),ActionStatus::Running,$draft->correlationId,
    );
    $worker->assertAuthorized($action);
    $reject(static fn()=> $untrustedWorker->assertAuthorized($action),'worker untrusted Plan proof');
    $permissionState->setEnabled($org,'sales',false);
    $reject(static fn()=> $worker->assertAuthorized($action),'worker disabled module after Action claim');
    $permissionState->setEnabled($org,'sales',true);
    $db->executeStatement(
        'UPDATE cos_approvals SET decided_by_id=:actor WHERE organization_id=:org AND action_id=:action',
        ['actor'=>$actorId,...$keys],
    );
    $reject(static fn()=> $worker->assertAuthorized($action),'worker self approved Action');
    $db->executeStatement(
        'UPDATE cos_approvals SET decided_by_id=:actor WHERE organization_id=:org AND action_id=:action',
        ['actor'=>$approvedBy,...$keys],
    );
    $db->executeStatement(
        'UPDATE cos_policy_evaluations SET decision="DENIED" WHERE organization_id=:org AND action_id=:action',
        $keys,
    );
    $reject(static fn()=> $worker->assertAuthorized($action),'worker Policy denied after Action claim');
    $db->executeStatement(
        'UPDATE cos_policy_evaluations SET decision="APPROVAL_REQUIRED" WHERE organization_id=:org AND action_id=:action',
        $keys,
    );
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET claim_expires_at=DATE_SUB(NOW(6),INTERVAL 1 SECOND)
         WHERE organization_id=:org AND run_id=:run AND step_id="send"',
        ['org'=>$org,'run'=>$identity->runId],
    );
    $reject(static fn()=> $worker->assertAuthorized($action),'expired technical step claim');
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET claim_expires_at=DATE_ADD(NOW(6),INTERVAL 90 SECOND)
         WHERE organization_id=:org AND run_id=:run AND step_id="send"',
        ['org'=>$org,'run'=>$identity->runId],
    );
    $db->executeStatement(
        'INSERT INTO cos_action_attempts
         (organization_id,action_id,attempt,worker_id,status,started_at)
         VALUES (:org,:action,2,"second-worker","RUNNING",NOW(6))', $keys,
    );
    $reject(static fn()=> $worker->assertAuthorized($action),'second Action worker attempt');
    $db->executeStatement(
        'DELETE FROM cos_action_attempts WHERE organization_id=:org AND action_id=:action AND attempt=2',
        $keys,
    );
    $db->executeStatement(
        'UPDATE cos_actions SET parameters=:params WHERE organization_id=:org AND id=:action',
        ['params'=>json_encode(['approved'=>false,'count'=>2],JSON_THROW_ON_ERROR),...$keys],
    );
    $reject(static fn()=> $worker->assertAuthorized($action),'worker canonical Action input drift');
    $db->executeStatement(
        'UPDATE cos_actions SET parameters=:params WHERE organization_id=:org AND id=:action',
        ['params'=>json_encode(['approved'=>true,'count'=>2],JSON_THROW_ON_ERROR),...$keys],
    );
    $worker->assertAuthorized($action);

    // Global stale recovery must quarantine federation/workflow external
    // effects for reconciliation while preserving ordinary retry behavior.
    $db->executeStatement(
        'UPDATE cos_actions SET started_at=DATE_SUB(NOW(6),INTERVAL 15 MINUTE)
         WHERE organization_id=:org AND id=:action', $keys,
    );
    $fedId=bin2hex(random_bytes(16));
    $plainId=bin2hex(random_bytes(16));
    foreach ([$fedId=>'fed:'.hash('sha256','federation-'.$token),
              $plainId=>'normal:'.hash('sha256','normal-'.$token)] as $extraId=>$extraKey) {
        $db->executeStatement(
            'INSERT INTO cos_actions
             (id,organization_id,type,target_type,target_id,parameters,source_type,source_id,
              status,execution_mode,risk_level,idempotency_key,correlation_id,started_at)
             VALUES (:id,:org,"test.publish","case","123",:params,"USER",:actor,
                     "RUNNING","APPROVAL_REQUIRED","HIGH",:key,:correlation,
                     DATE_SUB(NOW(6),INTERVAL 15 MINUTE))',
            ['id'=>$extraId,'org'=>$org,
             'params'=>json_encode(['count'=>2,'approved'=>true],JSON_THROW_ON_ERROR),
             'actor'=>$actorId,'key'=>$extraKey,'correlation'=>$draft->correlationId],
        );
    }
    $pdo=$db->getNativeConnection();
    if(!$pdo instanceof PDO) throw new RuntimeException('Expected native PDO-backed MySQL store.');
    (new MysqlActionRepository($pdo))->requeueStale(60);
    $statuses=$db->fetchAllKeyValue(
        'SELECT id,status FROM cos_actions WHERE organization_id=:org AND id IN (:wf,:fed,:plain)',
        ['org'=>$org,'wf'=>$actionId,'fed'=>$fedId,'plain'=>$plainId],
    );
    if(($statuses[$actionId]??null)!=='RUNNING'
        || ($statuses[$fedId]??null)!=='RUNNING'
        || ($statuses[$plainId]??null)!=='QUEUED') {
        throw new RuntimeException('Stale recovery replay protection or normal retry behavior regressed.');
    }

    echo "Workflow Action claim + worker gate MySQL passed: preclaim, live Policy/Approval, no second attempts, expired lease and protected stale requeue.\n";
} finally {
    $db->executeStatement('DELETE FROM cos_approvals WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_policy_evaluations WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_action_attempts WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_actions WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_steps WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_runs WHERE organization_id=:org',['org'=>$org]);
}
