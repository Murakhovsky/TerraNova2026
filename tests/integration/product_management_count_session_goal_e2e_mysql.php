<?php
declare(strict_types=1);

/**
 * Isolated REAL Product E5 journey:
 * Physical stock count proposal -> native Goal and pinned Plan -> independent Plan approval
 * evidence -> canonical Platform Workflow -> independently approved Action
 * -> Product Service -> native count and correction ledger -> run-linked Goal outcome.
 *
 * Plan Approval fixture is stored as in the canonical Property E2E. Action
 * Approval is decided through the real repository. No Shopify writes.
 */
if(getenv('COS_TEST_PM_SESSION_FULL')!=='1'){
    throw new RuntimeException('Explicit full Product stock count session Goal rollout flag required.');
}
$root=dirname(__DIR__,2);
require (string)(getenv('COS_TEST_AUTOLOAD')?:$root.'/symfony/vendor/autoload.php');
spl_autoload_register(static function(string $class)use($root):void{
    if(!str_starts_with($class,'App\\'))return;
    $path=$root.'/symfony/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use App\Persistence\Federation\FederationCanonicalWorkflowBridge;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use App\Persistence\Federation\FederationGoalStore;
use App\Persistence\Federation\FederationPlanApprovalEvidenceReader;
use App\Persistence\Federation\FederationTrustedOutcomeEvidenceResolver;
use App\Persistence\ProductManagement\ProductStockCountSessionPlanProposalService;
use App\Persistence\ProductManagement\ProductStockCountSessionRunOutcomeProvider;
use App\Persistence\Workflow\DoctrineWorkflowActionClaimAdmission;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use App\Persistence\Workflow\DoctrineWorkflowActionWorkerAdmission;
use App\Persistence\Workflow\DoctrineWorkflowExecutionCoordinator;
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\FailClosedWorkflowNonActionReceiptEvidence;
use App\Persistence\Workflow\FederationWorkflowPlanApprovalEvidence;
use App\Persistence\Workflow\WorkflowPredecessorReceiptVerifier;
use Doctrine\DBAL\DriverManager;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Automation\Action\ProductStockCountSessionApplyHandler;
use Domains\ProductManagement\Bootstrap\ProductManagementDomainModule;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlProductManagementStore;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;
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
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;
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
$pdo=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$password,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db=DriverManager::getConnection(['driver'=>'pdo_mysql','host'=>$host,'port'=>$port,
    'dbname'=>$name,'user'=>$user,'password'=>$password,'charset'=>'utf8mb4']);
$token=bin2hex(random_bytes(7));$org='pm-goal-'.$token;
$owner='42';$reviewer='pm-independent-'.$token;
$runId='run-pm-'.$token;$planApproval=bin2hex(random_bytes(16));
$assert=static function(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('Product approved Goal E2E: '.$why);
};
$deny=static function(callable $fn,string $why):void{
    try{$fn();}catch(Throwable){return;}
    throw new RuntimeException('Unsafe Product E2E admitted: '.$why);
};
$actor=new TenantContext(UserId::fromString($owner),
    OrganizationId::fromString($org),OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::MANAGE)]);
$oldRollout=getenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
putenv('COS_FEDERATION_CANONICAL_ORGANIZATION='.$org);

try {
    $pdo->prepare('INSERT INTO cos_organizations (id,name,status) VALUES (:id,:name,"ACTIVE")')
        ->execute(['id'=>$org,'name'=>'Product approved full canonical E2E']);
    $transactions=new class($pdo) implements TransactionManagerInterface {
        public function __construct(private PDO $pdo){}
        public function transactional(callable $op):mixed{
            if($this->pdo->inTransaction())return $op();
            $this->pdo->beginTransaction();
            try{$result=$op();$this->pdo->commit();return $result;}
            catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        }
        public function isActive():bool{return $this->pdo->inTransaction();}
        public function afterCommit(callable $fn):void{
            if($this->pdo->inTransaction())throw new RuntimeException('Uncommitted test-local afterCommit');
            $fn();
        }
    };
    $products=new ProductManagementService(
        new MySqlProductManagementStore($pdo,new MysqlEventStore($pdo)));
    $manifest=require $root.'/app/Domains/ProductManagement/module.php';
    $catalog=new ModuleCatalog([
        ModuleDefinition::fromArray($manifest),
        new ModuleDefinition(new ModuleManifest('federation','Federation','1.0.0'),
            new ModuleContributions()),
    ]);
    $states=new class implements ModuleStateRepositoryInterface {
        public function enabledOverride(string $org,string $moduleId):?bool{return true;}
        public function setEnabled(string $org,string $moduleId,bool $value):void{}
    };
    $modules=new ActiveModuleResolver($catalog,$states);
    $handler=new ProductStockCountSessionApplyHandler($products,$modules);
    $domains=new DomainModuleRegistry([new ProductManagementDomainModule(countSessionHandler:$handler)]);
    $capabilities=new CanonicalCapabilityCatalog($catalog);
    $bindings=new FederationCapabilityBindingResolver($capabilities,$domains,$modules);
    $planReader=new FederationPlanApprovalEvidenceReader($db);
    $planEvidence=new FederationWorkflowPlanApprovalEvidence($db,$planReader);
    $receipts=new DoctrineWorkflowActionReceiptVerifier($db,$planEvidence);
    $admission=new DoctrineWorkflowActionClaimAdmission($db,$planEvidence,$capabilities,$modules);
    $store=new DoctrineWorkflowExecutionStore($db,$receipts,$admission);
    $worker=new DoctrineWorkflowActionWorkerAdmission($db,$planEvidence,$capabilities,$modules,
        new WorkflowPredecessorReceiptVerifier($receipts,new FailClosedWorkflowNonActionReceiptEvidence()));
    $gate=new ModuleActionExecutionGate($domains,$modules,null,$worker);
    $actionService=new ActionService(new MysqlActionRepository($pdo),
        new ActionExecutor([$handler],$gate));
    $approvalRepo=new MysqlApprovalRepository($pdo);
    $policies=new MysqlPolicyRepository($pdo);
    $engine=new PolicyEngine(new ConditionEvaluator());
    $contexts=new PolicyContextBuilder($domains);
    $policyService=new ActionPolicyService($actionService,$policies,
        new MysqlPolicyEvaluationRepository($pdo),$approvalRepo,$engine,
        $transactions,null,$domains,$modules,$contexts);
    $outcome=new ProductStockCountSessionRunOutcomeProvider($db,$products,$receipts);
    $goals=new FederationGoalStore($db,$bindings,new GoalPlanValidator($capabilities),
        new GoalOutcomeEvaluator(),
        new FederationTrustedOutcomeEvidenceResolver($modules,[$outcome]),$planReader);
    $scheduler=new WorkflowStepScheduler();
    $bridge=new FederationCanonicalWorkflowBridge($db,$store,$scheduler,$goals,
        $planEvidence,$receipts,new FailClosedWorkflowNonActionReceiptEvidence(),
        $planReader,$capabilities,$domains,$modules,$policies,$engine,$contexts,$policyService,
        new DoctrineWorkflowExecutionCoordinator($db,$store,$scheduler,$planEvidence,$receipts));
    $planner=new ProductStockCountSessionPlanProposalService($db,$products,$goals);

    $product=$products->createProduct($org,['name'=>'Count E2E','sku'=>'COUNT'.strtoupper($token),'type'=>'stock'],'count-product-'.$token,$owner,'count-e2e');
    $loc=$products->createLocation($org,'COUNT-WH','Count warehouse','count-loc-'.$token,$owner,'count-e2e')['location_id'];
    $variant=$product['variant_id'];
    $products->recordMovement($org,$variant,$loc,'5','receipt','count-seed','count-seed-'.$token,$owner,'count-e2e');
    $revision=(int)$products->balance($org,$variant,$loc)['revision'];
    $second=$products->createProduct($org,['name'=>'Second SKU','sku'=>'SECOND'.strtoupper($token),'type'=>'stock'],'count-second-'.$token,$owner,'count-e2e')['variant_id'];
    $products->recordMovement($org,$second,$loc,'2','receipt','count-seed-second','count-seed-second-'.$token,$owner,'count-e2e');
    $secondRevision=(int)$products->balance($org,$second,$loc)['revision'];
    $rows=[['variant_id'=>$variant,'counted'=>'3.5','expected_revision'=>$revision],['variant_id'=>$second,'counted'=>'2','expected_revision'=>$secondRevision]];
    $key=bin2hex(random_bytes(16));
    $proposal=$planner->propose($actor,$loc,$rows,'count:E2E',$key);
    $assert($planner->propose($actor,$loc,array_reverse($rows),'count:E2E',$key)===$proposal,'Reordered proposal replay');
    $changed=$rows;$changed[0]['counted']='1';
    $deny(fn()=>$planner->propose($actor,$loc,$changed,'count:E2E',$key),'Changed proposal payload');
    $goalId=$proposal['goal_id'];$planId=$proposal['plan_id'];$sessionId=$proposal['session_id'];
    $assert($products->countSession($org,$sessionId)===null && $products->balance($org,$variant,$loc)['on_hand']==='5.000000','Plan proposal wrote stock');
    $assert(count($products->countCandidates($org,$loc))===2,'Count candidates missing');
    $assert($products->countCandidates('foreign-'.$token,$loc)===[],'Foreign warehouse candidate leak');

    $pdo->prepare('INSERT INTO cos_policies
        (id,organization_id,code,name,action_type,conditions,decision,priority,version,status)
        VALUES (:id,:org,"pm-count-e2e","Product independent Action approval",
                "product_management.stock.count.session.apply",:conditions,"APPROVAL_REQUIRED",1,1,"ACTIVE")')
        ->execute(['id'=>'policy-'.$token,'org'=>$org,'conditions'=>'[]']);
    // Independent Plan Approval evidence fixture, as in Property canonical E2E.
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
        'idempotency_key'=>'plan:'.$token,'correlation_id'=>'pm-plan-'.$token,
    ]);
    $db->insert('cos_policy_evaluations',[
        'id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,'action_id'=>$planApproval,
        'decision'=>'APPROVAL_REQUIRED','correlation_id'=>'pm-plan-'.$token,
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
        'Platform Workflow was not sole execution authority');
    $deny(fn()=>$bridge->start($actor,'other-'.$token,$planId,$planApproval),
        'same Product Plan started twice');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='running',
        'canonical Run did not advance to running');
    $pending=$bridge->advance($actor,$runId,$planApproval);
    $assert(($pending['state']??null)==='awaiting_human_approval',
        'approved Plan failed to create independently approvable Action');
    $actionId=$pending['action_id']??null;
    $assert(is_string($actionId)&&$actionId!=='','approved Product Action ID missing');
    $assert($actionService->execute($org,$actionId,'pm-premature-worker')===null,
        'unapproved Product Action executed');
    $assert($products->countSession($org,$sessionId)===null && $products->balance($org,$variant,$loc)['on_hand']==='5.000000',
        'Pending Action changed native stock');
    $assert($bridge->advance($actor,$runId,$planApproval)['action_id']===$actionId,
        'repeated advance created another Action');
    $approvalId=(string)$db->fetchOne('SELECT id FROM cos_approvals
        WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>$actionId]);
    $assert($approvalId!=='','Action independent approval row missing');
    $assert($approvalRepo->decide($org,$approvalId,ApprovalStatus::Approved,$reviewer,'CI verified'),
        'independent Product Action approver decision failed');
    $actionService->queue($org,$actionId);
    $executed=$actionService->execute($org,$actionId,'pm-approved-worker');
    $assert($executed?->status===ActionStatus::Completed,
        'approved Product stock count session Action worker did not complete');
    $assert($actionService->execute($org,$actionId,'pm-second-worker')===null,
        'completed Product Action was executed a second time');
    $session=$products->countSession($org,$sessionId);
    $assert($session!==null&&$session['row_count']===2&&count($session['rows'])===2,'Native session missing rows');
    $assert($products->balance($org,$variant,$loc)['on_hand']==='3.500000'&&$products->balance($org,$second,$loc)['on_hand']==='2.000000','Atomic count balance mismatch');
    $assert(count(array_filter($session['rows'],fn($r)=>$r['movement_id']!==null))===1,'Only discrepancy writes ledger');
    $assert($products->countSession('foreign-'.$token,$sessionId)===null,'Foreign session leak');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='step_completed',
        'native Product Action receipt failed canonical Step attestation');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='completed',
        'Platform Workflow failed to complete after native count');
    $outcomeResult=$goals->recordEvaluation($actor,'pm-eval-'.$token,$runId);
    $assert(($outcomeResult['result']??null)==='satisfied'
        &&($outcomeResult['criteria'][0]['observed']??null)===2,
        'Goal not independently satisfied by native stock count and ledger');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM cos_actions
        WHERE organization_id=:org AND type=:type',
        ['org'=>$org,'type'=>ProductStockCountSessionApplyHandler::TYPE])===1,
        'Product stock count session submitted more than one canonical Action');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM cos_federation_runs
        WHERE organization_id=:org',['org'=>$org])===0,
        'legacy executor duplicated canonical Run');
    // Product-owned evidence drift must not be silently counted as success.
    $db->executeStatement('UPDATE pm_stock_count_sessions SET source_ref=:fake
        WHERE organization_id=:org AND session_id=:count',
        ['fake'=>'count:FORGED','org'=>$org,'count'=>$sessionId]);
    $deny(fn()=>$goals->recordEvaluation($actor,'pm-eval-tampered-'.$token,$runId),
        'tampered Product stock count session receipt satisfied Goal again');
    echo "Product stock count session approved Goal/Plan/Action/Outcome FULL native MySQL E2E PASS.\n";
} finally {
    if($oldRollout===false)putenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
    else putenv('COS_FEDERATION_CANONICAL_ORGANIZATION='.$oldRollout);
}
