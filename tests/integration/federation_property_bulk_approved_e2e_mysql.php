<?php
declare(strict_types=1);

/**
 * Entire isolated Property path: approved Goal/Plan -> canonical Platform
 * Workflow -> independently approved Kernel Action -> REAL native Property
 * writer 10 Assets -> Action receipt attestation -> Run -> Goal outcome.
 *
 * The first Plan approval is a durable fixture with independent approver,
 * as in federation_canonical_goal_e2e_mysql.php. The second Action approval
 * is decided through the actual Approval repository. No network side effects.
 */
if(getenv('COS_TEST_PROPERTY_BULK_APPROVED')!=='1') {
    throw new RuntimeException('Explicit isolated full Property acceptance flag required.');
}
require (string)(getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/symfony/vendor/autoload.php');
spl_autoload_register(static function(string $name):void {
    if(!str_starts_with($name,'App\\'))return;
    $file=dirname(__DIR__,2).'/symfony/src/'.str_replace('\\','/',substr($name,4)).'.php';
    if(is_file($file))require $file;
});

use App\Application\Property\Service\PropertyWriteService;
use App\Application\Capability\AutomationIntentSignatureVerifier;
use App\Infrastructure\Persistence\MySql\MysqlPropertyLocationReference;
use App\Infrastructure\Persistence\Property\PdoPropertyMutationReceipt;
use App\Persistence\Federation\FederationCanonicalWorkflowBridge;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use App\Persistence\Federation\FederationGoalStore;
use App\Persistence\Federation\FederationPlanApprovalEvidenceReader;
use App\Persistence\Federation\FederationTrustedOutcomeEvidenceResolver;
use App\Persistence\Property\PropertyBulkIntakeService;
use App\Persistence\Capability\CanonicalAutomationIntentStore;
use App\Persistence\Property\PropertyBulkPlanProposalService;
use App\Persistence\Property\PropertyBulkRunOutcomeProvider;
use App\Persistence\Workflow\DoctrineWorkflowActionClaimAdmission;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use App\Persistence\Workflow\DoctrineWorkflowActionWorkerAdmission;
use App\Persistence\Workflow\DoctrineWorkflowExecutionCoordinator;
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\FailClosedWorkflowNonActionReceiptEvidence;
use App\Persistence\Workflow\FederationWorkflowPlanApprovalEvidence;
use App\Persistence\Workflow\WorkflowPredecessorReceiptVerifier;
use Doctrine\DBAL\DriverManager;
use Domains\Property\Application\Service\CanonicalPropertyInventoryCommands;
use Domains\Property\Application\Service\PropertyCanonicalRuntimeService;
use Domains\Property\Automation\Action\PropertyBulkCreateHandler;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyCanonicalRuntimeRepository;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyProjection;
use Domains\Property\Infrastructure\ReadModel\MySql\MysqlPropertyReferencePort;
use Infrastructure\Platform\Persistence\MySql\Action\MysqlActionRepository;
use Infrastructure\Platform\Persistence\MySql\Approval\MysqlApprovalRepository;
use Infrastructure\Platform\Persistence\MySql\Policy\MysqlPolicyEvaluationRepository;
use Infrastructure\Platform\Persistence\MySql\Policy\MysqlPolicyRepository;
use Kernel\Action\ActionStatus;
use Kernel\Action\Service\ActionExecutor;
use Kernel\Action\Service\ActionService;
use Kernel\Action\Service\ModuleActionExecutionGate;
use Kernel\Approval\ApprovalStatus;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
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
use Kernel\Policy\Contract\PolicyRepositoryInterface;
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
use Platform\Workflow\Service\WorkflowStepScheduler;

$host=(string)(getenv('DB_HOST')?:'mysql');
$port=(int)(getenv('DB_PORT')?:3306);
$name=(string)(getenv('DB_DATABASE')?:'cos');
$user=(string)(getenv('DB_USERNAME')?:'cos');
$password=(string)(getenv('DB_PASSWORD')?:'');
$pdo=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$password,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db=DriverManager::getConnection(['driver'=>'pdo_mysql','host'=>$host,'port'=>$port,
    'dbname'=>$name,'user'=>$user,'password'=>$password,'charset'=>'utf8mb4']);
$token=bin2hex(random_bytes(7));
$org='bulk-goal-'.$token;
$owner='42';
$reviewer='approver-'.$token;
$runId='run-'.$token;
$planApproval=bin2hex(random_bytes(16));
$assert=static function(bool $yes,string $why):void {
    if(!$yes)throw new RuntimeException('Property canonical 10 E2E: '.$why);
};
$deny=static function(callable $fn,string $why):void {
    try{$fn();}catch(Throwable){return;}
    throw new RuntimeException('Unsafe Property canonical E2E admitted: '.$why);
};
$actor=new TenantContext(UserId::fromString($owner),
    OrganizationId::fromString($org),OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::MANAGE)]);
$oldRollout=getenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
putenv('COS_FEDERATION_CANONICAL_ORGANIZATION='.$org);

try {
    $pdo->prepare('INSERT INTO cos_organizations (id,name,status) VALUES (:id,:name,"ACTIVE")')
        ->execute(['id'=>$org,'name'=>'Property 10 canonical test isolated tenant']);
    $transactions=new class($pdo) implements TransactionManagerInterface {
        public function __construct(private PDO $pdo){}
        public function transactional(callable $operation):mixed {
            if($this->pdo->inTransaction())return $operation();
            $this->pdo->beginTransaction();
            try{$value=$operation();$this->pdo->commit();return $value;}
            catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        }
        public function isActive():bool{return $this->pdo->inTransaction();}
        public function afterCommit(callable $callback):void {
            if($this->pdo->inTransaction())throw new RuntimeException('Pending test-local transaction');
            $callback();
        }
    };
    $events=new class implements EventStoreInterface {
        public int $count=0;
        public function append(DomainEvent $event):void {$this->count++;}
        public function find(string $eventId):?DomainEvent{return null;}
        public function findByAggregate(string $organizationId,string $aggregateType,
            string $aggregateId,int $limit=100):array{return [];}
    };
    $audit=new class implements AuditRepositoryInterface {
        public int $count=0;
        public function append(AuditEntry $entry):void {$this->count++;}
    };
    $references=new MysqlPropertyReferencePort($pdo);
    $repository=new MysqlPropertyCanonicalRuntimeRepository($pdo);
    $projection=new MysqlPropertyProjection($pdo,new MysqlPropertyLocationReference($pdo));
    $runtime=new PropertyCanonicalRuntimeService($repository,$projection,new EventBus($events,$transactions),$transactions);
    $writer=new PropertyWriteService($runtime,new CanonicalPropertyInventoryCommands($runtime),
        $repository,$references,new PdoPropertyMutationReceipt($pdo),$transactions,$audit);
    $intake=new PropertyBulkIntakeService($db,$references,$writer);
    $handler=new PropertyBulkCreateHandler($intake);
    $propertyModule=new class($handler) implements DomainModuleInterface,ActionOwningModuleInterface {
        public function __construct(private PropertyBulkCreateHandler $handler){}
        public function name():string{return 'property';}
        public function actionTypes():array{return [PropertyBulkCreateHandler::TYPE];}
        public function actionHandlers():array{return [$this->handler];}
    };
    $domains=new DomainModuleRegistry([$propertyModule]);
    $contract=new CapabilityContract(
        id:'property.bulk.create',version:'1.0.0',ownerDomain:'property',kind:'command',
        inputSchema:'resources/contracts/property/bulk-create-input.schema.json',
        outputSchema:'resources/contracts/property/bulk-create-output.schema.json',
        executionBinding:'action:property.bulk.create',async:true,sideEffectLevel:'external',
        permission:TenantPermissions::MANAGE,approvalPolicy:'required',idempotency:'required',
        timeoutPolicy:['seconds'=>300],retryPolicy:['max_attempts'=>1],
        failureContract:'Partial batch must be reconciled, never retried blindly',
    );
    $catalog=new ModuleCatalog([
        new ModuleDefinition(new ModuleManifest('property','Property','1.0.0'),
            new ModuleContributions(capabilities:['property.bulk.create'],capabilityContracts:[$contract])),
        new ModuleDefinition(new ModuleManifest('federation','Federation','1.0.0'),
            new ModuleContributions()),
    ]);
    $states=new class implements ModuleStateRepositoryInterface {
        public function enabledOverride(string $org,string $module):?bool{return true;}
        public function setEnabled(string $org,string $module,bool $enabled):void {}
    };
    $modules=new ActiveModuleResolver($catalog,$states);
    $capabilities=new CanonicalCapabilityCatalog($catalog);
    $bindings=new FederationCapabilityBindingResolver($capabilities,$domains,$modules);
    $planReader=new FederationPlanApprovalEvidenceReader($db);
    $planEvidence=new FederationWorkflowPlanApprovalEvidence($db,$planReader);
    $receipts=new DoctrineWorkflowActionReceiptVerifier($db,$planEvidence);
    $admission=new DoctrineWorkflowActionClaimAdmission($db,$planEvidence,$capabilities,$modules);
    $store=new DoctrineWorkflowExecutionStore($db,$receipts,$admission);
    $worker=new DoctrineWorkflowActionWorkerAdmission($db,$planEvidence,$capabilities,$modules,
        new WorkflowPredecessorReceiptVerifier($receipts,new FailClosedWorkflowNonActionReceiptEvidence()));
    $gateway=new ModuleActionExecutionGate($domains,$modules,null,$worker);
    $actionService=new ActionService(new MysqlActionRepository($pdo),
        new ActionExecutor([$handler],$gateway));
    $approvalRepo=new MysqlApprovalRepository($pdo);
    $policies=new MysqlPolicyRepository($pdo);
    $engine=new PolicyEngine(new ConditionEvaluator());
    $contexts=new PolicyContextBuilder($domains);
    $policyService=new ActionPolicyService($actionService,$policies,
        new MysqlPolicyEvaluationRepository($pdo),$approvalRepo,$engine,
        $transactions,null,$domains,$modules,$contexts);
    $provider=new PropertyBulkRunOutcomeProvider($db,$references,$receipts);
    $goals=new FederationGoalStore($db,$bindings,new GoalPlanValidator($capabilities),
        new GoalOutcomeEvaluator(),
        new FederationTrustedOutcomeEvidenceResolver($modules,[$provider]),$planReader);
    $scheduler=new WorkflowStepScheduler();
    $bridge=new FederationCanonicalWorkflowBridge($db,$store,$scheduler,$goals,
        $planEvidence,$receipts,new FailClosedWorkflowNonActionReceiptEvidence(),
        $planReader,$capabilities,$domains,$modules,$policies,$engine,$contexts,$policyService,
        new DoctrineWorkflowExecutionCoordinator($db,$store,$scheduler,$planEvidence,$receipts));
    $planner=new PropertyBulkPlanProposalService($db,$intake,$goals);

    $batchRows=[];
    for($i=0;$i<10;$i++) {
        $batchRows[]=['type_code'=>$i%2?'apartment':'house','city'=>'Львів',
            'region'=>'Львівська','address'=>'Goal test '.$token.' street '.($i+1),
            'area_total'=>75.0+$i,'rooms'=>2];
    }
    // Real CRON HMAC boundary submits a non-executable intent. The
    // manager explicitly adopts it before any native Property mutation.
    $signatures=new AutomationIntentSignatureVerifier(str_repeat('a',40),
        str_repeat('c',40),$org);
    $intents=new CanonicalAutomationIntentStore($db,$signatures,$intake);
    $raw=json_encode(['capability_id'=>'property.bulk.create','items'=>$batchRows],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $ts=(string)time();
    $nonce=bin2hex(random_bytes(16));
    $sig=hash_hmac('sha256',implode("\n",['cron',$ts,$nonce,$raw]),str_repeat('c',40));
    $machine=$intents->submit('cron',$ts,$nonce,$sig,$raw);
    $assert(($machine['state']??null)==='pending_review','CRON impersonated approved Action.');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM cos_federation_plans
        WHERE organization_id=:org',['org'=>$org])===0,'Machine created a Plan without human review.');
    $adopted=$intents->adopt($actor,$machine['intent_id'],$machine['digest']);
    $assert($adopted['state']==='adopted','Human CRON intent adoption did not persist.');
    $batch=$intake->preview($actor,$adopted['batch_id']);
    $assert($batch['count']===10,'Signed CRON import lost Property rows.');
    $plan=$planner->propose($actor,$batch['batch_id'],$batch['digest']);
    $assert(($plan['state']??null)==='planned','Real Goal/Plan proposal not persisted.');
    $goalId=$plan['goal_id'];
    $planId=$plan['plan_id'];
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM cos_federation_goals
        WHERE organization_id=:org AND goal_id=:goal',
        ['org'=>$org,'goal'=>$goalId])===1,'Federation Goal was not created.');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM tn_property_assets WHERE organization_id=:org',
        ['org'=>$org])===0,'Planning leaked native Property writes.');

    $policyId='policy-'.$token;
    $pdo->prepare('INSERT INTO cos_policies
        (id,organization_id,code,name,action_type,conditions,decision,priority,version,status)
        VALUES (:id,:org,"property-e2e","Property independent Action approval",
                "property.bulk.create",:conditions,"APPROVAL_REQUIRED",1,1,"ACTIVE")')
        ->execute(['id'=>$policyId,'org'=>$org,'conditions'=>'[]']);
    // First Plan Approval is a persisted independent human fixture, precisely
    // matching the trusted Plan binding that Production reads before Run start.
    $db->executeStatement('UPDATE cos_federation_plans SET state=:state
        WHERE organization_id=:org AND plan_id=:plan',
        ['state'=>'approved','org'=>$org,'plan'=>$planId]);
    $storedPlan=(string)$db->fetchOne('SELECT plan_json FROM cos_federation_plans
        WHERE organization_id=:org AND plan_id=:plan',['org'=>$org,'plan'=>$planId]);
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
        'id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,'action_id'=>$planApproval,
        'decision'=>'APPROVAL_REQUIRED','correlation_id'=>'plan-'.$token,
        'evaluated_at'=>gmdate('Y-m-d H:i:s.u'),
    ]);
    $db->insert('cos_approvals',[
        'id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,'action_id'=>$planApproval,
        'status'=>'APPROVED','approver_type'=>'USER','approver_id'=>$reviewer,
        'requested_by_type'=>'USER','requested_by_id'=>$owner,
        'decided_by_type'=>'USER','decided_by_id'=>$reviewer,
        'decided_at'=>gmdate('Y-m-d H:i:s.u'),
        'expires_at'=>(new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s.u'),
    ]);
    $assert($bridge->start($actor,$runId,$planId,$planApproval)['execution_authority']==='platform_workflow',
        'Platform Workflow did not become sole executor.');
    $deny(fn()=>$bridge->start($actor,'second-'.$token,$planId,$planApproval),
        'second canonical execution for same Plan');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='running',
        'Run CREATED to RUNNING failed.');
    $pending=$bridge->advance($actor,$runId,$planApproval);
    $assert($pending['state']==='awaiting_human_approval','No independently approved Action request.');
    $actionId=$pending['action_id'];
    $assert(is_string($actionId),'Canonical Action ID missing.');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM tn_property_assets WHERE organization_id=:org',
        ['org'=>$org])===0,'Pending Action created native assets before Approval.');
    $assert($actionService->execute($org,$actionId,'premature-property-worker')===null,
        'Worker bypassed human approval.');
    $assert($bridge->advance($actor,$runId,$planApproval)['action_id']===$actionId,
        'Repeated advance issued a second Action.');
    $approvalId=(string)$db->fetchOne('SELECT id FROM cos_approvals
        WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>$actionId]);
    $assert($approvalId!=='','Missing Action Approval.');
    $assert($approvalRepo->decide($org,$approvalId,ApprovalStatus::Approved,$reviewer,'CI verified'),
        'Independent approver was rejected.');
    $actionService->queue($org,$actionId);
    $executed=$actionService->execute($org,$actionId,'property-canonical-worker');
    $assert($executed?->status===ActionStatus::Completed,'Property canonical Action was not completed.');
    $assert($actionService->execute($org,$actionId,'second-worker')===null,
        'Completed Action was executed again.');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM tn_property_assets WHERE organization_id=:org',
        ['org'=>$org])===10,'Real native Property Asset cardinality is not ten.');
    $assert($events->count===10&&$audit->count===10,'Real Property writer was not used.');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='step_completed',
        'Verified Action receipt did not checkpoint canonical Step.');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='completed',
        'Platform Workflow did not complete after native writes.');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM cos_federation_runs WHERE organization_id=:org',
        ['org'=>$org])===0,'Legacy executor received duplicate Runs.');
    $result=$goals->recordEvaluation($actor,'eval-'.$token,$runId);
    $assert(($result['result']??null)==='satisfied','Goal not independently satisfied.');
    $assert(($result['criteria'][0]['observed']??null)===10,
        'Goal outcome was not ten separately verified native Assets.');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM cos_actions
        WHERE organization_id=:org AND type=:type',['org'=>$org,'type'=>'property.bulk.create'])===1,
        'Canonical Action was submitted more than once.');
    // Native evidence drift cannot silently pass through a second evaluation.
    $db->executeStatement('UPDATE cos_property_bulk_items SET receipt_key=:fake
        WHERE organization_id=:org AND batch_id=:batch AND item_index=0',[
        'fake'=>'forged-native-receipt','org'=>$org,'batch'=>$batch['batch_id'],
    ]);
    $deny(fn()=>$goals->recordEvaluation($actor,'eval-tampered-'.$token,$runId),
        'forged native Property receipt in outcome evaluation.');
    echo "Property federation approved ten Assets FULL MySQL E2E PASS.\n";
} finally {
    if($oldRollout===false)putenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
    else putenv('COS_FEDERATION_CANONICAL_ORGANIZATION='.$oldRollout);
}
