<?php
declare(strict_types=1);

/**
 * Isolated REAL Product E3 journey:
 * CSV preview -> native Goal and pinned Plan -> independent Plan approval
 * evidence -> canonical Platform Workflow -> independently approved Action
 * -> Product Service -> native receipts -> run-linked Goal outcome.
 *
 * Plan Approval fixture is stored as in the canonical Property E2E. Action
 * Approval is decided through the real repository. No Shopify writes.
 */
if(getenv('COS_TEST_PM_SHOPIFY_FULL')!=='1'){
    throw new RuntimeException('Explicit full Product Shopify Goal rollout flag required.');
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
use App\Persistence\ProductManagement\ShopifyImportPlanProposalService;
use App\Persistence\ProductManagement\ShopifyImportRunOutcomeProvider;
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
use Domains\ProductManagement\Application\Service\PricingService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyImportPreviewService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyImportApplyService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyObservationStore;
use Domains\ProductManagement\Automation\Action\ShopifyImportApplyHandler;
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
    $pricing=new PricingService($pdo,new MysqlEventStore($pdo));
    $observations=new ShopifyObservationStore($pdo);
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
    $staging=new ShopifyImportPreviewService($pdo,$observations,$modules);
    $writer=new ShopifyImportApplyService($pdo,$products,$pricing,$observations);
    $handler=new ShopifyImportApplyHandler($writer,$modules);
    $domains=new DomainModuleRegistry([new ProductManagementDomainModule(null,$handler)]);
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
    $outcome=new ShopifyImportRunOutcomeProvider($db,$writer,$receipts);
    $goals=new FederationGoalStore($db,$bindings,new GoalPlanValidator($capabilities),
        new GoalOutcomeEvaluator(),
        new FederationTrustedOutcomeEvidenceResolver($modules,[$outcome]),$planReader);
    $scheduler=new WorkflowStepScheduler();
    $bridge=new FederationCanonicalWorkflowBridge($db,$store,$scheduler,$goals,
        $planEvidence,$receipts,new FailClosedWorkflowNonActionReceiptEvidence(),
        $planReader,$capabilities,$domains,$modules,$policies,$engine,$contexts,$policyService,
        new DoctrineWorkflowExecutionCoordinator($db,$store,$scheduler,$planEvidence,$receipts));
    $planner=new ShopifyImportPlanProposalService($db,$observations,$goals,$modules);

    $sku1='GOAL'.strtoupper($token).'A';$sku2='GOAL'.strtoupper($token).'B';
    $conn=$observations->provision($org,'pm-approved-e2e.myshopify.com','shopify_env_primary');
    $gid='gid://shopify/Product/90001';
    $remote=[
        'id'=>$gid,'title'=>'Independently Approved Shopify Product','status'=>'ACTIVE',
        'variants'=>['nodes'=>[
            ['id'=>'gid://shopify/ProductVariant/90002','sku'=>$sku1,'price'=>'14.25','barcode'=>null,
             'inventoryItem'=>['id'=>'gid://shopify/InventoryItem/90003','tracked'=>true,
                'inventoryLevels'=>['nodes'=>[],'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]]]],
            ['id'=>'gid://shopify/ProductVariant/90004','sku'=>$sku2,'price'=>'15.50','barcode'=>null,
             'inventoryItem'=>['id'=>'gid://shopify/InventoryItem/90005','tracked'=>false,
                'inventoryLevels'=>['nodes'=>[],'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]]]],
        ],'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]],
    ];
    $observations->recordShopCurrency($org,$conn,'USD');
    $observations->observeProduct($org,$conn,$gid,$remote,true);
    $hash=$observations->productSnapshot($org,$conn,$gid)['payload_hash'];
    $preview=$staging->stage($org,$conn,$gid,$owner,$hash);
    $assert($products->searchProducts($org,$sku1)===[],
        'Shopify staging created canonical Products prematurely.');
    $proposal=$planner->propose($actor,$preview['preview_id'],$hash,$preview['normalized_digest']);
    $assert(($proposal['state']??null)==='proposed'&&$proposal['ready_variants']===2,
        'Canonical Shopify Goal/Plan proposal missing.');
    $deny(fn()=>$planner->propose($actor,$preview['preview_id'],$hash,$preview['normalized_digest']),
        'Shopify import preview planned twice.');
    $goalId=$proposal['goal_id'];$planId=$proposal['plan_id'];
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM cos_federation_goals
        WHERE organization_id=:org AND goal_id=:goal',['org'=>$org,'goal'=>$goalId])===1,
        'Canonical Shopify Goal not persisted.');
    $assert($products->searchProducts($org,$sku1)===[],'Shopify Plan approval wrote native Product.');

    $pdo->prepare('INSERT INTO cos_policies
        (id,organization_id,code,name,action_type,conditions,decision,priority,version,status)
        VALUES (:id,:org,"pm-import-e2e","Product independent Action approval",
                "product_management.shopify.import.apply",:conditions,"APPROVAL_REQUIRED",1,1,"ACTIVE")')
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
    $assert($products->searchProducts($org,$sku1)===[],
        'pending Product Action created native Products');
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
        'approved Product Shopify Action worker did not complete');
    $assert($actionService->execute($org,$actionId,'pm-second-worker')===null,
        'completed Product Action was executed a second time');
    $assert(count($products->searchProducts($org,$sku1))===1
        &&count($products->searchProducts($org,$sku2))===1,
        'Both Shopify Variants must be independently searchable.');
    $native=$products->searchProducts($org,$sku1)[0];
    $p=$products->getProduct($org,$native['product_id']);
    $assert(count($p['variants'])===2,'Shopify import split Variants into multiple Products.');
    $assert($observations->mapping($org,$conn,'product',$gid)['canonical_id']===$native['product_id'],
        'Approved Shopify source mapping missing.');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='step_completed',
        'native Product Action receipt failed canonical Step attestation');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='completed',
        'Platform Workflow failed to complete after native Products');
    $outcomeResult=$goals->recordEvaluation($actor,'pm-eval-'.$token,$runId);
    $assert(($outcomeResult['result']??null)==='satisfied'
        &&($outcomeResult['criteria'][0]['observed']??null)===2,
        'Goal was not independently satisfied by 2 native Product receipts');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM cos_actions
        WHERE organization_id=:org AND type=:type',
        ['org'=>$org,'type'=>ShopifyImportApplyHandler::TYPE])===1,
        'Product Shopify submitted more than one canonical Action');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM cos_federation_runs
        WHERE organization_id=:org',['org'=>$org])===0,
        'legacy executor duplicated canonical Run');
    // A forged native receipt must not be counted as a trusted Goal result.
    $db->executeStatement('UPDATE pm_shopify_import_step_receipts SET payload_digest=:fake
        WHERE organization_id=:org AND preview_id=:preview AND step_key=:step',
        ['fake'=>str_repeat('0',64),'org'=>$org,
         'preview'=>$preview['preview_id'],'step'=>'variant:0']);
    $deny(fn()=>$goals->recordEvaluation($actor,'pm-eval-tampered-'.$token,$runId),
        'forged Shopify Variant receipt satisfied Goal again.');
    echo "Shopify approved Goal/Plan/Action/Outcome FULL native MySQL E2E PASS.\n";
} finally {
    if($oldRollout===false)putenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
    else putenv('COS_FEDERATION_CANONICAL_ORGANIZATION='.$oldRollout);
}
