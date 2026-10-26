<?php
declare(strict_types=1);

/**
 * End-to-end MySQL acceptance of the opt-in Federation canonical Goal path.
 * Real GoalStore, Platform Workflow Store, ActionService/MysqlActionRepository,
 * last-moment worker gate, Sales Action handler, Action receipts, Sales-owned
 * native task read model, and Goal evaluation.
 * The CRM external boundary is test-local: no network or real customer mutation.
 */
require (string)(getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__, 2).'/symfony/vendor/autoload.php');

use App\Persistence\Federation\FederationCanonicalWorkflowBridge;
use App\Persistence\Federation\FederatedActionAdmission;
use App\Persistence\Federation\FederationExternalActionReceiptReconciler;
use App\Persistence\Federation\FederationVerifiedRunReadModel;
use App\Persistence\Federation\FederationCanonicalReceiptInspector;
use App\Persistence\Federation\FederationHistoricalRunCutover;
use App\Persistence\Federation\FederationMigrationOperatorAuthorization;
use App\Persistence\Federation\FederationPostCutoverIntegrity;
use App\Persistence\Workflow\DoctrineWorkflowShadowParityReader;
use Platform\Workflow\Service\WorkflowShadowParityComparator;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use App\Persistence\Federation\FederationGoalStore;
use App\Persistence\Federation\FederationPlanApprovalEvidenceReader;
use App\Persistence\Federation\FederationTrustedOutcomeEvidenceResolver;
use App\Persistence\Workflow\DoctrineWorkflowActionClaimAdmission;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use App\Persistence\Workflow\DoctrineWorkflowActionWorkerAdmission;
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\FailClosedWorkflowNonActionReceiptEvidence;
use App\Persistence\Workflow\FederationWorkflowPlanApprovalEvidence;
use App\Persistence\Workflow\WorkflowPredecessorReceiptVerifier;
use Doctrine\DBAL\DriverManager;
use Domains\Sales\Application\Contract\CrmGatewayInterface;
use Domains\Sales\Application\DTO\CreateTaskCommand;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\SendMessageCommand;
use Domains\Sales\Application\DTO\ScheduleFollowupCommand;
use Domains\Sales\Application\Service\SalesLocalTaskOutcomeEvidenceProvider;
use Domains\Sales\Automation\Action\CreateFollowupTaskHandler;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesLocalTaskOutcomeReadModel;
use Domains\Sales\Model\DealChangeSet;
use Infrastructure\Platform\Persistence\MySql\Action\MysqlActionRepository;
use Infrastructure\Platform\Persistence\MySql\Approval\MysqlApprovalRepository;
use Infrastructure\Platform\Persistence\MySql\Policy\MysqlPolicyEvaluationRepository;
use Infrastructure\Platform\Persistence\MySql\Policy\MysqlPolicyRepository;
use Kernel\Action\ActionStatus;
use Kernel\Action\Service\ActionExecutor;
use Kernel\Action\Service\ActionService;
use Kernel\Action\Service\ModuleActionExecutionGate;
use Kernel\Approval\ApprovalStatus;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\CapabilityContract;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;
use Kernel\Policy\ActionPolicy;
use Kernel\Policy\Contract\PolicyRepositoryInterface;
use Kernel\Policy\PolicyDecision;
use Kernel\Policy\Service\ActionPolicyService;
use Kernel\Policy\Service\PolicyContextBuilder;
use Kernel\Policy\Service\PolicyEngine;
use Kernel\Rule\Service\ConditionEvaluator;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Platform\Orchestration\Goal\GoalOutcomeEvaluator;
use Platform\Orchestration\Goal\GoalPlanValidator;
use Platform\Orchestration\Goal\GoalSpecification;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Service\WorkflowStepScheduler;

$host=(string)(getenv('DB_HOST') ?: 'mysql');
$port=(int)(getenv('DB_PORT') ?: 3306);
$name=(string)(getenv('DB_DATABASE') ?: 'cos');
$user=(string)(getenv('DB_USERNAME') ?: 'cos');
$password=(string)(getenv('DB_PASSWORD') ?: '');
$pdo=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$password,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$db=DriverManager::getConnection([
    'driver'=>'pdo_mysql','host'=>$host,'port'=>$port,'dbname'=>$name,
    'user'=>$user,'password'=>$password,'charset'=>'utf8mb4',
]);
$token=bin2hex(random_bytes(7));
$org='fed-e2e-'.$token;
$owner='owner-'.$token;
$approver='approver-'.$token;
$goalId='goal-'.$token;
$planId='plan-'.$token;
$runId='run-'.$token;
$planApproval=bin2hex(random_bytes(16));
$oldRollout=getenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
putenv('COS_FEDERATION_CANONICAL_ORGANIZATION='.$org);
$actor=new TenantContext(
    UserId::fromString($owner),OrganizationId::fromString($org),
    OrganizationRole::fromString('manager'),[Permission::fromString(TenantPermissions::MANAGE)],
);
$assert=static function(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException('Canonical Goal MySQL acceptance: '.$message);
};
$reject=static function(callable $fn,string $why):void {
    try{$fn();}catch(Throwable){return;}
    throw new RuntimeException('Canonical Goal accepted unsafe action: '.$why);
};
$rows=static function(string $table)use($db,$org):int {
    return (int)$db->fetchOne("SELECT COUNT(*) FROM $table WHERE organization_id=:org",['org'=>$org]);
};

$caseId=0;
$personId=0;
try {
    $pdo->prepare('INSERT INTO cos_organizations (id,name,status) VALUES (:org,:name,"ACTIVE")')
        ->execute(['org'=>$org,'name'=>'Federation acceptance isolated tenant']);
    $pdo->prepare('INSERT INTO tn_people (organization_id,public_id,full_name)
                   VALUES (:org,:public,:name)')
        ->execute(['org'=>$org,'public'=>'PN-'.$token,'name'=>'MySQL acceptance test person']);
    $personId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO tn_client_cases
        (organization_id,public_id,person_id,type,title,status)
        VALUES (:org,:public,:person,"buy",:title,"active")')
        ->execute(['org'=>$org,'public'=>'CC-'.$token,'person'=>$personId,'title'=>'Acceptance case']);
    $caseId=(int)$pdo->lastInsertId();

    // Only the CRM boundary is isolated. The real Sales Action handler writes
    // a native Sales activity through this test-local gateway, never to a
    // caller-supplied Goal result or Action receipt projection.
    $crm=new class($pdo,$org,$caseId) implements CrmGatewayInterface {
        public int $calls=0;
        public function __construct(private PDO $db,private string $org,private int $caseId){}
        public function createTask(CreateTaskCommand $command):OperationResult {
            if($command->organizationId!==$this->org
                || $command->dealReference!==(string)$this->caseId){
                return OperationResult::failure('Foreign case or tenant');
            }
            ++$this->calls;
            $this->db->prepare('INSERT INTO tn_client_case_activities
                (organization_id,client_case_id,activity_type,title,body)
                VALUES (:org,:id,"task",:title,:body)')
                ->execute(['org'=>$this->org,'id'=>$this->caseId,
                    'title'=>$command->title,'body'=>$command->body]);
            return OperationResult::success((string)$this->db->lastInsertId());
        }
        public function send(SendMessageCommand $command):OperationResult {
            return OperationResult::failure('Not enabled in acceptance fixture');
        }
        public function schedule(ScheduleFollowupCommand $command):OperationResult {
            return OperationResult::failure('Not enabled in acceptance fixture');
        }
        public function update(string $organizationId,string $dealReference,DealChangeSet $changes):OperationResult {
            return OperationResult::failure('Not enabled in acceptance fixture');
        }
    };
    $handler=new CreateFollowupTaskHandler($crm);
    $sales=new class($handler) implements DomainModuleInterface,ActionOwningModuleInterface {
        public function __construct(private CreateFollowupTaskHandler $handler){}
        public function name():string{return 'sales';}
        public function actionTypes():array{return ['sales.create_task'];}
        public function actionHandlers():array{return [$this->handler];}
    };
    $domains=new DomainModuleRegistry([$sales]);
    $contract=new CapabilityContract(
        id:'sales.create_task',version:'1.0.0',ownerDomain:'sales',kind:'command',
        inputSchema:'resources/contracts/sales/create-task-input.schema.json',
        outputSchema:'resources/contracts/sales/create-task-output.schema.json',
        executionBinding:'action:sales.create_task',async:true,sideEffectLevel:'external',
        permission:TenantPermissions::MANAGE,approvalPolicy:'required',idempotency:'required',
        timeoutPolicy:['seconds'=>90],retryPolicy:['max_attempts'=>1],
        failureContract:'Uncertain CRM response requires operator reconciliation',
    );
    $catalog=new ModuleCatalog([
        new ModuleDefinition(new ModuleManifest('sales','Sales','1.0.0'),
            new ModuleContributions(capabilities:['sales.create_task'],capabilityContracts:[$contract])),
        new ModuleDefinition(new ModuleManifest('federation','Federation','1.0.0'),
            new ModuleContributions()),
    ]);
    $states=new class implements ModuleStateRepositoryInterface {
        public function enabledOverride(string $organizationId,string $moduleId):?bool {return true;}
        public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void {}
    };
    $modules=new ActiveModuleResolver($catalog,$states);
    $capabilities=new CanonicalCapabilityCatalog($catalog);
    $bindings=new FederationCapabilityBindingResolver($capabilities,$domains,$modules);
    $planReader=new FederationPlanApprovalEvidenceReader($db);
    $planEvidence=new FederationWorkflowPlanApprovalEvidence($db,$planReader);
    $receipts=new DoctrineWorkflowActionReceiptVerifier($db,$planEvidence,$capabilities);
    $claimAdmission=new DoctrineWorkflowActionClaimAdmission($db,$planEvidence,$capabilities,$modules);
    $store=new DoctrineWorkflowExecutionStore($db,$receipts,$claimAdmission);
    $workerAdmission=new DoctrineWorkflowActionWorkerAdmission($db,$planEvidence,$capabilities,$modules,
        new WorkflowPredecessorReceiptVerifier($receipts,new FailClosedWorkflowNonActionReceiptEvidence()));
    $gateway=new ModuleActionExecutionGate($domains,$modules,null,$workerAdmission);
    $executor=new ActionExecutor([$handler],$gateway);
    $repository=new MysqlActionRepository($pdo);
    $actionService=new ActionService($repository,$executor);
    $approvals=new MysqlApprovalRepository($pdo);
    $transactions=new class($pdo) implements TransactionManagerInterface {
        public function __construct(private PDO $pdo){}
        public function transactional(callable $operation):mixed{
            if($this->pdo->inTransaction())return $operation();
            $this->pdo->beginTransaction();
            try{$out=$operation();$this->pdo->commit();return $out;}
            catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        }
        public function isActive():bool{return $this->pdo->inTransaction();}
        public function afterCommit(callable $callback):void {
            if($this->pdo->inTransaction())throw new LogicException('Test does not support deferred callbacks');
            $callback();
        }
    };
    // Policy is genuinely stored in MySQL, not an in-memory decision. This
    // also exercises the FK from cos_policy_evaluations to cos_policies.
    $policyId='policy-'.$token;
    $pdo->prepare('INSERT INTO cos_policies
        (id,organization_id,code,name,action_type,conditions,decision,priority,version,status)
        VALUES (:id,:org,"acceptance-goal","Federation E2E human gate",
                "sales.create_task",:conditions,"APPROVAL_REQUIRED",1,1,"ACTIVE")')
        ->execute(['id'=>$policyId,'org'=>$org,'conditions'=>'[]']);
    $policies=new MysqlPolicyRepository($pdo);
    $policyEngine=new PolicyEngine(new ConditionEvaluator());
    $contexts=new PolicyContextBuilder($domains);
    $policyService=new ActionPolicyService($actionService,$policies,
        new MysqlPolicyEvaluationRepository($pdo),$approvals,$policyEngine,
        $transactions,null,$domains,$modules,$contexts);
    $goals=new FederationGoalStore($db,$bindings,new GoalPlanValidator($capabilities),
        new GoalOutcomeEvaluator(),new FederationTrustedOutcomeEvidenceResolver($modules,[
            new SalesLocalTaskOutcomeEvidenceProvider(new MysqlSalesLocalTaskOutcomeReadModel($pdo)),
        ]),$planReader);
    $bridge=new FederationCanonicalWorkflowBridge($db,$store,new WorkflowStepScheduler(),
        $goals,$planEvidence,$receipts,
        new \App\Persistence\Workflow\FailClosedWorkflowNonActionReceiptEvidence(),
        $planReader,$capabilities,$domains,$modules,
        $policies,$policyEngine,$contexts,$policyService,
        new \App\Persistence\Workflow\DoctrineWorkflowExecutionCoordinator(
            $db,$store,new WorkflowStepScheduler(),$planEvidence,$receipts));

    $goal=new GoalSpecification($goalId,$org,$owner,'Створити одну Sales CRM задачу',
        [['id'=>'sales.local_tasks_created','operator'=>'at_least','expected'=>1]],
        ['sales.create_task']);
    $goals->createGoal($actor,$goal);
    $steps=[[
        'id'=>'task','depends_on'=>[],'capability_id'=>'sales.create_task',
        'capability_version'=>'1.0.0','owner_domain'=>'sales',
        'execution_binding'=>'action:sales.create_task','side_effect_level'=>'external',
        'approval_policy'=>'required','idempotency'=>'required',
        'input'=>['target_type'=>'deal','target_id'=>(string)$caseId,
            'parameters'=>['title'=>'Canonical Goal MySQL acceptance task']],
    ]];
    $db->insert('cos_federation_plans',[
        'organization_id'=>$org,'plan_id'=>$planId,'goal_id'=>$goalId,
        'spec_version'=>1,'plan_version'=>1,'state'=>'approved',
        'plan_json'=>json_encode(['steps'=>$steps],JSON_THROW_ON_ERROR),
        'created_at'=>gmdate('Y-m-d H:i:s.u'),
    ]);
    $storedPlan=(string)$db->fetchOne(
        'SELECT plan_json FROM cos_federation_plans WHERE organization_id=:org AND plan_id=:plan',
        ['org'=>$org,'plan'=>$planId],
    );
    $db->insert('cos_actions',[
        'id'=>$planApproval,'organization_id'=>$org,'type'=>'cos.federation.plan.approval',
        'target_type'=>'cos_federation_plan','target_id'=>$planId,
        'parameters'=>json_encode(['goal_id'=>$goalId,'plan_id'=>$planId,
            'specification_version'=>1,'plan_hash'=>hash('sha256',$storedPlan)],JSON_THROW_ON_ERROR),
        'source_type'=>'USER','source_id'=>$owner,'status'=>'COMPLETED',
        'execution_mode'=>'APPROVAL_REQUIRED','risk_level'=>'HIGH',
        'idempotency_key'=>'plan:'.$token,'correlation_id'=>'plan-'.$token,
    ]);
    $db->insert('cos_policy_evaluations',[
        'id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,
        'action_id'=>$planApproval,'decision'=>'APPROVAL_REQUIRED',
        'correlation_id'=>'plan-'.$token,'evaluated_at'=>gmdate('Y-m-d H:i:s.u'),
    ]);
    $db->insert('cos_approvals',[
        'id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,
        'action_id'=>$planApproval,'status'=>'APPROVED',
        'approver_type'=>'USER','approver_id'=>$approver,
        'requested_by_type'=>'USER','requested_by_id'=>$owner,
        'decided_by_type'=>'USER','decided_by_id'=>$approver,
        'decided_at'=>gmdate('Y-m-d H:i:s.u'),
        'expires_at'=>(new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s.u'),
    ]);

    $assert($bridge->newRunsEnabled($actor),'opt-in not enabled');
    $created=$bridge->start($actor,$runId,$planId,$planApproval);
    $assert(($created['execution_authority']??null)==='platform_workflow','run not canonical');
    $assert($rows('cos_workflow_runs')===1 && $rows('cos_workflow_steps')===1
        && $rows('cos_federation_runs')===0 && $rows('cos_federation_steps')===0,
        'technical run duplicated in legacy');
    $reject(static fn()=> $bridge->start($actor,'duplicate-'.$token,$planId,$planApproval),
        'second execution for one approved Plan');
    // A Run's persisted input digest is immutable. Receipt audit must not
    // accept a mutated digest or substitute the approved Plan digest later.
    $originalDigest=(string)$db->fetchOne(
        'SELECT input_digest FROM cos_workflow_runs
         WHERE organization_id=:org AND run_id=:run',
        ['org'=>$org,'run'=>$runId],
    );
    $assert(preg_match('/^[a-f0-9]{64}$/',$originalDigest)===1,
        'canonical Run did not persist an input digest');
    $db->executeStatement(
        'UPDATE cos_workflow_runs SET input_digest=:tampered
         WHERE organization_id=:org AND run_id=:run',
        ['tampered'=>str_repeat('0',64),'org'=>$org,'run'=>$runId],
    );
    $reject(static fn()=> $bridge->advance($actor,$runId,$planApproval),
        'tampered canonical Run input digest');
    $assert($rows('cos_actions')===1 && $rows('cos_action_attempts')===0,
        'tampered Run dispatched an Action');
    $db->executeStatement(
        'UPDATE cos_workflow_runs SET input_digest=:original
         WHERE organization_id=:org AND run_id=:run',
        ['original'=>$originalDigest,'org'=>$org,'run'=>$runId],
    );
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='running',
        'canonical created-to-running transition failed');
    $reject(static fn()=> $bridge->advance($actor,$runId,'wrong-approval'),
        'forged Plan Approval');
    $pdo->prepare('UPDATE cos_policies SET decision="DENIED" WHERE id=:id')
        ->execute(['id'=>$policyId]);
    $reject(static fn()=> $bridge->advance($actor,$runId,$planApproval),
        'latest MySQL policy denied Action admission');
    $assert($rows('cos_actions')===1,'denied Policy persisted an unauthorized external Action');
    $pdo->prepare('UPDATE cos_policies SET decision="APPROVAL_REQUIRED" WHERE id=:id')
        ->execute(['id'=>$policyId]);
    $pending=$bridge->advance($actor,$runId,$planApproval);
    if($pending['state']!=='awaiting_human_approval'){
        // A controlled CI-only diagnostic: no handler execution, no replay.
        // Catch the precise Store claim/admission failure rather than weakening
        // the fail-closed result returned to production operators.
        $debugIdentity=new WorkflowRunIdentity($org,$runId);
        $debugRun=$store->get($debugIdentity);
        if($debugRun!==null){
            try{
                $store->claim(new WorkflowStepClaim(
                    new WorkflowRunCommand($debugIdentity,$actor,$debugRun->revision,$debugRun->correlationId),
                    'task','ci-claim-diagnostic',60,
                ));
                fwrite(STDERR,"DIAGNOSTIC: direct canonical claim succeeded after bridge rejection.\n");
            }catch(Throwable $error){
                fwrite(STDERR,'DIAGNOSTIC: '.get_class($error).': '.$error->getMessage()."\n");
            }
        }
        fwrite(STDERR,'DIAGNOSTIC: bridge state='.(string)$pending['state']."\n");
    }
    $assert($pending['state']==='awaiting_human_approval','Action not submitted through Policy service');
    $actionId=$pending['action_id']??null;
    $assert(is_string($actionId),'missing Action id');
    $identity=new WorkflowRunIdentity($org,$runId);
    $key='wf:'.$store->steps($identity)[0]->idempotencyKey;
    $assert((string)$db->fetchOne(
        'SELECT status FROM cos_actions WHERE organization_id=:org AND id=:id',
        ['org'=>$org,'id'=>$actionId])==='PENDING_APPROVAL','Action skipped human approval');
    $assert((string)$db->fetchOne(
        'SELECT state FROM cos_workflow_steps WHERE organization_id=:org AND run_id=:run',
        ['org'=>$org,'run'=>$runId])==='claimed','no exclusive canonical claim');
    $assert($actionService->execute($org,$actionId,'premature-worker')===null,
        'Action ran before independent approval');
    $assert($crm->calls===0,'native Sales handler executed before approval');
    $assert($bridge->advance($actor,$runId,$planApproval)['action_id']===$actionId,
        'repeated Advance created a second Action');
    $assert((int)$db->fetchOne(
        'SELECT COUNT(*) FROM cos_actions WHERE organization_id=:org AND idempotency_key=:key',
        ['org'=>$org,'key'=>$key])===1,'duplicate canonical Action idempotency key');

    $approvalId=(string)$db->fetchOne(
        'SELECT id FROM cos_approvals WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>$actionId]);
    $assert($approvalId!=='','Action approval was not created');
    $assert($approvals->decide($org,$approvalId,ApprovalStatus::Approved,$approver,'CI authorized'),
        'independent human approval failed');
    $actionService->queue($org,$actionId);
    // TIMESTAMP second precision: the native task must be after the
    // microsecond-precision canonical Run creation.
    sleep(2);
    $executed=$actionService->execute($org,$actionId,'ci-canonical-worker');
    $assert($executed?->status===ActionStatus::Completed,'real Action worker did not finish');
    $assert($crm->calls===1,'Sales handler executed more than once');
    $assert($actionService->execute($org,$actionId,'duplicate-worker')===null,
        'duplicate worker acquired already completed Action');

    $db->executeStatement(
        'UPDATE cos_action_attempts SET status="FAILED" WHERE organization_id=:org AND action_id=:id',
        ['org'=>$org,'id'=>$actionId],
    );
    $bad=$bridge->advance($actor,$runId,$planApproval);
    $assert($bad['state']==='manual_reconciliation_required','forged receipt completed Step');
    $assert($crm->calls===1,'uncertain receipt retried external handler');
    $db->executeStatement(
        'UPDATE cos_action_attempts SET status="COMPLETED" WHERE organization_id=:org AND action_id=:id',
        ['org'=>$org,'id'=>$actionId],
    );
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='step_completed',
        'verified first-attempt Action receipt not checkpointed');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='completed',
        'Goal canonical Workflow Run did not finalize');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='completed',
        'completed Run was replayed');
    $assert($crm->calls===1,'completed Run re-executed Sales task');
    $assert($rows('cos_federation_runs')===0 && $rows('cos_federation_steps')===0,
        'legacy Run/Step received duplicate writes');

    $canonicalAudit=new FederationCanonicalReceiptInspector(
        $db,$planEvidence,$receipts,new FailClosedWorkflowNonActionReceiptEvidence(),
    );
    $assert($canonicalAudit->inspect($org,$runId)['status']==='verified',
        'Fleet attestation failed on completed canonical Action');
    // The Golden 50 read path must recognize canonical Runs and perform its
    // own independent Action/Policy/Approval/attempt attestation. All SQL
    // mutations below are isolated test-tenant tamper fixtures, never workers.
    $migrationAudit=new FederationHistoricalRunCutover(
        $db,$store,new DoctrineWorkflowShadowParityReader(
            $db,$store,new WorkflowShadowParityComparator(),$planEvidence,
            $receipts,new FailClosedWorkflowNonActionReceiptEvidence(),
        ),$planReader,new FederationMigrationOperatorAuthorization($db),
        new FederationPostCutoverIntegrity(
            $db,$planEvidence,$receipts,new FailClosedWorkflowNonActionReceiptEvidence(),
        ),
    );
    $nativeReader=new FederationVerifiedRunReadModel(
        $db,$receipts,$migrationAudit,
        new FederationExternalActionReceiptReconciler(
            $db,$goals,new FederatedActionAdmission($db,$capabilities,$planReader),
        ),
    );
    $proof=$nativeReader->forPlan($actor,$planId);
    $assert(($proof['authority']??null)==='canonical'
        && ($proof['run_id']??null)===$runId
        && ($proof['run_state']??null)==='completed',
        'Golden 50 reader failed to resolve the canonical Plan authority');
    $stepProof=$nativeReader->step($actor,$runId,'task');
    $assert(is_array($stepProof) && ($stepProof['authority']??null)==='canonical'
        && $nativeReader->actionKey($stepProof)===$key,
        'Golden 50 reader failed to pin the canonical Action idempotency key');
    $assert(count($nativeReader->stepsForRun($actor,$runId,'canonical'))===1,
        'Golden 50 reader lost the canonical Step set');
    $assert($nativeReader->requireCompletedAction($actor,$runId,'task')===$actionId,
        'Golden 50 reader did not independently attest completed canonical Action');
    $db->executeStatement(
        'UPDATE cos_action_attempts SET status="FAILED"
         WHERE organization_id=:org AND action_id=:id',
        ['org'=>$org,'id'=>$actionId],
    );
    $reject(static fn()=> $nativeReader->requireCompletedAction($actor,$runId,'task'),
        'Golden 50 read admitted a tampered successful Action attempt');
    $assert($canonicalAudit->inspect($org,$runId)['status']==='drift',
        'Fleet accepted a forged canonical receipt');
    $db->executeStatement(
        'UPDATE cos_action_attempts SET status="COMPLETED"
         WHERE organization_id=:org AND action_id=:id',
        ['org'=>$org,'id'=>$actionId],
    );
    $assert($nativeReader->requireCompletedAction($actor,$runId,'task')===$actionId,
        'Golden 50 canonical Action proof could not recover after restored evidence');
    $foreignReaderActor=new TenantContext(
        UserId::fromString('foreign-'.$token),
        OrganizationId::fromString('foreign-'.$token),
        OrganizationRole::fromString('manager'),
        [Permission::fromString(TenantPermissions::MANAGE)],
    );
    $assert($nativeReader->forPlan($foreignReaderActor,$planId)===null
        && $nativeReader->step($foreignReaderActor,$runId,'task')===null,
        'Golden 50 native read leaked an execution across tenants');
    $reject(static fn()=> $nativeReader->requireCompletedAction($foreignReaderActor,$runId,'task'),
        'Golden 50 foreign tenant Action proof was admitted');

    $db->executeStatement(
        'INSERT INTO cos_federation_runs
         (organization_id,run_id,goal_id,plan_id,state,revision,created_at,updated_at)
         VALUES (:org,:run,:goal,:plan,"pending",1,NOW(6),NOW(6))',
        ['org'=>$org,'run'=>'collision-'.$token,'goal'=>$goalId,'plan'=>$planId],
    );
    $reject(static fn()=> $nativeReader->forPlan($actor,$planId),
        'Golden 50 silently selected one of two conflicting Plan authorities');
    $db->executeStatement(
        'DELETE FROM cos_federation_runs WHERE organization_id=:org AND run_id=:run',
        ['org'=>$org,'run'=>'collision-'.$token],
    );
    $assert($nativeReader->forPlan($actor,$planId)['authority']==='canonical',
        'Canonical authority did not recover after removing test-only collision');

    $outcome=$goals->recordEvaluation($actor,'eval-'.$token,$runId);
    $assert(($outcome['result']??null)==='satisfied','native Sales Goal outcome not satisfied');
    $assert(($outcome['criteria'][0]['observed']??null)===1,
        'Goal outcome did not read one native MySQL Sales activity');
    $assert(($outcome['criteria'][0]['source']??null)==='sales.tn_client_case_activities.task.v1',
        'Outcome came from fabricated Action flag, not domain-owned Sales data');
    $assert($rows('cos_federation_evaluations')===1,
        'native Goal evaluation was not persisted');
    $assert($rows('cos_action_attempts')===1,'worker retried Action');
    $assert((int)$db->fetchOne(
        'SELECT COUNT(*) FROM tn_client_case_activities
         WHERE organization_id=:org AND activity_type="task"',
        ['org'=>$org])===1,'Sales wrote more than one native task');
    echo "Federation canonical Goal full MySQL E2E PASS: Goal/Plan -> Approval -> one Action -> worker -> attested receipt -> Run -> native Sales Outcome; negative replay/receipt/tenant fences.\n";
} finally {
    // Isolated test tenant only. Retain no temporary business records.
    foreach (['cos_federation_evaluations','cos_workflow_steps','cos_workflow_runs',
        'cos_approvals','cos_policy_evaluations','cos_action_attempts','cos_actions',
        'cos_policies','cos_federation_steps','cos_federation_runs','cos_federation_plans',
        'cos_federation_goal_specs','cos_federation_goals',
        'tn_client_case_activities','tn_client_cases','tn_people'] as $table) {
        $pdo->prepare("DELETE FROM $table WHERE organization_id=:org")->execute(['org'=>$org]);
    }
    $pdo->prepare('DELETE FROM cos_organizations WHERE id=:org')->execute(['org'=>$org]);
    if($oldRollout===false)putenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
    else putenv('COS_FEDERATION_CANONICAL_ORGANIZATION='.$oldRollout);
}
