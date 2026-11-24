<?php
declare(strict_types=1);

/** Disposable native multi-step business acceptance. Actual Plan and Action approval services and worker admission are exercised; no inserted approval receipt fixtures. */
if(getenv('COS_TEST_PM_GOVERNED_FULL')!=='1'){
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
use App\Persistence\Federation\FederationPlanApproveHandler;
use App\Persistence\Federation\FederationPlanApprovalCoordinator;
use App\Infrastructure\Automation\FederationPlanApprovalAuthority;
use Domains\Federation\Bootstrap\FederationDomainModule;
use Kernel\Approval\Service\ApprovalService;
use Platform\Orchestration\Goal\GoalPlanApprovalRequestFactory;
use App\Persistence\Federation\FederationPlanApprovalEvidenceReader;
use App\Persistence\Federation\FederationTrustedOutcomeEvidenceResolver;
use App\Persistence\ProductManagement\ProductGovernedPlanProposalService;
use App\Persistence\Capability\CanonicalAutomationIntentStore;
use App\Application\Capability\AutomationIntentSignatureVerifier;
use Domains\ProductManagement\Application\Service\PricingService;
use App\Persistence\ProductManagement\ProductGovernedRunOutcomeProvider;
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
use Domains\ProductManagement\Automation\Action\ProductGovernedOperationHandler;
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
$runId='run-pm-'.$token;$planApproval=bin2hex(random_bytes(16));
$assert=static function(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('Product approved Goal E2E: '.$why);
};
$deny=static function(callable $fn,string $why):void{
    try{$fn();}catch(Throwable){return;}
    throw new RuntimeException('Unsafe Product E2E admitted: '.$why);
};
$oldRollout=getenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
putenv('COS_FEDERATION_CANONICAL_ORGANIZATION='.$org);

try {
    $pdo->prepare('INSERT INTO cos_organizations (id,name,status) VALUES (:id,:name,"ACTIVE")')
        ->execute(['id'=>$org,'name'=>'Product approved full canonical E2E']);
    $users=[];
    foreach(['owner','reviewer'] as $role){
        $pdo->prepare("INSERT INTO tn_users (organization_id,email,password_hash,full_name,role,status) VALUES (?,?,?,?, 'manager','active')")
            ->execute([$org,$role.'-'.$token.'@example.test',password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),'Product QA '.$role]);
        $id=(string)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO cos_organization_memberships (organization_id,user_id,role,status) VALUES (?,?,'manager','ACTIVE') ON DUPLICATE KEY UPDATE role='manager',status='ACTIVE'")->execute([$org,$id]);
        $users[$role]=$id;
    }
    $owner=$users['owner'];$reviewer=$users['reviewer'];
    $actor=new TenantContext(UserId::fromString($owner),
    OrganizationId::fromString($org),OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]);

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
    $prices=new PricingService($pdo,new MysqlEventStore($pdo));
    $capabilities=new CanonicalCapabilityCatalog($catalog);
    $handler=new ProductGovernedOperationHandler($products,$prices,$modules,$capabilities);
    $planReader=new FederationPlanApprovalEvidenceReader($db);
    $planHandler=new FederationPlanApproveHandler($db,$planReader);
    $domains=new DomainModuleRegistry([new ProductManagementDomainModule(governedHandler:$handler),new FederationDomainModule($planHandler)]);
    $bindings=new FederationCapabilityBindingResolver($capabilities,$domains,$modules);
    $planEvidence=new FederationWorkflowPlanApprovalEvidence($db,$planReader);
    $receipts=new DoctrineWorkflowActionReceiptVerifier($db,$planEvidence);
    $admission=new DoctrineWorkflowActionClaimAdmission($db,$planEvidence,$capabilities,$modules);
    $store=new DoctrineWorkflowExecutionStore($db,$receipts,$admission);
    $worker=new DoctrineWorkflowActionWorkerAdmission($db,$planEvidence,$capabilities,$modules,
        new WorkflowPredecessorReceiptVerifier($receipts,new FailClosedWorkflowNonActionReceiptEvidence()));
    $gate=new ModuleActionExecutionGate($domains,$modules,null,$worker);
    $actionService=new ActionService(new MysqlActionRepository($pdo),
        new ActionExecutor([$handler,$planHandler],$gate));
    $approvalRepo=new MysqlApprovalRepository($pdo);
    $policies=new MysqlPolicyRepository($pdo);
    $engine=new PolicyEngine(new ConditionEvaluator());
    $contexts=new PolicyContextBuilder($domains);
    $policyService=new ActionPolicyService($actionService,$policies,
        new MysqlPolicyEvaluationRepository($pdo),$approvalRepo,$engine,
        $transactions,null,$domains,$modules,$contexts);
    $outcome=new ProductGovernedRunOutcomeProvider($db,$products,$prices,$receipts);
    $goals=new FederationGoalStore($db,$bindings,new GoalPlanValidator($capabilities),
        new GoalOutcomeEvaluator(),
        new FederationTrustedOutcomeEvidenceResolver($modules,[$outcome]),$planReader);
    $scheduler=new WorkflowStepScheduler();
    $bridge=new FederationCanonicalWorkflowBridge($db,$store,$scheduler,$goals,
        $planEvidence,$receipts,new FailClosedWorkflowNonActionReceiptEvidence(),
        $planReader,$capabilities,$domains,$modules,$policies,$engine,$contexts,$policyService,
        new DoctrineWorkflowExecutionCoordinator($db,$store,$scheduler,$planEvidence,$receipts));
    $product=$products->createProduct($org,['name'=>'Governed stock','sku'=>'GOV'.strtoupper($token),'type'=>'stock'],'seed-product',$owner,'governed-e2e');
    $loc=$products->createLocation($org,'GOV-WH','Governed warehouse','seed-location',$owner,'governed-e2e')['location_id'];
    $destination=$products->createLocation($org,'GOV-DST','Governed destination','seed-destination',$owner,'governed-e2e')['location_id'];
    $listId=$prices->createList($org,'GOV-B2B','Governed wholesale','wholesale','USD','seed-list',$owner,'governed-e2e')['list_id'];
    $variant=$product['variant_id'];$goalId='goal-'.$token;$planId='plan-'.$token;
    $category=$products->createCategory($org,'Governed category','governed-category',null,'seed-category',$owner,'governed-e2e')['category_id'];
    $products->assignCategory($org,$product['product_id'],$category,'seed-category-assign',$owner,'governed-e2e');
    $products->defineAttribute($org,['code'=>'color','name'=>'Color','value_type'=>'enum','choices'=>['black','white']],'seed-color',$owner,'governed-e2e');
    $products->bindAttribute($org,$category,'color',false,true,'seed-color-bind',$owner,'governed-e2e');
    $products->defineAttribute($org,['code'=>'notes','name'=>'Notes','value_type'=>'text'],'seed-notes',$owner,'governed-e2e');
    $products->bindAttribute($org,$category,'notes',false,false,'seed-notes-bind',$owner,'governed-e2e');
    $revision=$products->productAttributes($org,$product['product_id'])['revision'];
    $second=$products->createProduct($org,['name'=>'Release fixture','sku'=>'REL'.strtoupper($token),'type'=>'stock'],'seed-release-product',$owner,'governed-e2e');
    $products->recordMovement($org,$second['variant_id'],$loc,'2','receipt','QA-BASE','seed-release-stock',$owner,'governed-e2e');
    $reservation=$products->reserveStock($org,$second['variant_id'],$loc,'1','manual:SEED','seed-reservation',$owner,'governed-e2e')['reservation_id'];
    // Separate lifecycle targets prevent a later archive from invalidating an earlier update's exact readback.
    $lifecycle=[];
    foreach(['product-update','product-archive','variant-update','variant-archive'] as $kind){
        $seed=$products->createProduct($org,['name'=>'Lifecycle '.$kind,'sku'=>'LC'.strtoupper($kind).strtoupper($token),'type'=>'stock'],'seed-'.$kind,$owner,'governed-e2e');
        $lifecycle[$kind]=$products->getProduct($org,$seed['product_id']);
    }
    $catalogSeed=$products->createProduct($org,['name'=>'Catalog fixture','sku'=>'CAT'.strtoupper($token),'type'=>'stock'],'seed-catalog',$owner,'governed-e2e');
    $steps=[];
    foreach([
        ['receive','stock.receive',['variant_id'=>$variant,'location_id'=>$loc,'quantity'=>'10','source_ref'=>'QA-RECEIPT']],
        ['price','price.base.set',['variant_id'=>$variant,'currency'=>'USD','amount'=>'125.5','expected_revision'=>0]],
        ['reserve','stock.reserve',['variant_id'=>$variant,'location_id'=>$loc,'quantity'=>'3','source_ref'=>'manual:QA-ORDER']],
        ['issue','stock.issue',['variant_id'=>$variant,'location_id'=>$loc,'quantity'=>'2','source_ref'=>'QA-ISSUE']],
        ['create','product.create',['name'=>'New assortment','sku'=>'NEW'.strtoupper($token),'type'=>'stock']],
        ['matrix','variant.matrix.create',['product_id'=>$product['product_id'],'axes'=>[['code'=>'color','choices'=>['black','white']]],'sku_prefix'=>'MATRIX'.$token,'expected_revision'=>$revision]],
        ['bulk','attribute.product.bulk_set',['products'=>[['product_id'=>$product['product_id'],'expected_revision'=>$revision+1]],'values'=>[['code'=>'notes','value'=>'QA validated']]]],
        ['release','stock.release',['reservation_id'=>$reservation,'reason'=>'QA cancellation']],
        ['transfer','stock.transfer',['variant_id'=>$variant,'from_location_id'=>$loc,'to_location_id'=>$destination,'quantity'=>'2','source_ref'=>'QA-TRANSFER']],
        ['list-price','price.list.entry.set',['list_id'=>$listId,'variant_id'=>$variant,'amount'=>'99.25','expected_revision'=>0]],
        ['product-update','product.update',['product_id'=>$lifecycle['product-update']['product_id'],'expected_revision'=>$lifecycle['product-update']['revision'],'name'=>'Approved rename','brand'=>'QA']],
        ['product-archive','product.archive',['product_id'=>$lifecycle['product-archive']['product_id'],'expected_revision'=>$lifecycle['product-archive']['revision']]],
        ['variant-update','variant.update',['variant_id'=>$lifecycle['variant-update']['variants'][0]['variant_id'],'expected_revision'=>$lifecycle['variant-update']['variants'][0]['revision'],'barcode'=>'QA123','options'=>[['name'=>'color','value'=>'blue']]]],
        ['variant-archive','variant.archive',['variant_id'=>$lifecycle['variant-archive']['variants'][0]['variant_id'],'expected_revision'=>$lifecycle['variant-archive']['variants'][0]['revision']]],
        ['category-create','category.create',['name'=>'Approved category','slug'=>'approved-category']],
        ['category-assign','category.assign',['product_id'=>$catalogSeed['product_id'],'category_id'=>$category]],
        ['translate','translation.upsert',['product_id'=>$catalogSeed['product_id'],'locale'=>'uk','name'=>'Approved translation','description'=>'QA']],
        ['variant-create','variant.create',['product_id'=>$catalogSeed['product_id'],'sku'=>'ADDED'.strtoupper($token),'uom'=>'pcs','track_inventory'=>true]],
        ['location-create','location.create',['code'=>'QA-NEW','name'=>'Approved warehouse']],
    ] as [$id,$op,$input]){
        $steps[]=['id'=>$id,'capability_id'=>ProductGovernedOperationHandler::PREFIX.$op,'capability_version'=>'1.0.0',
            'input'=>['target_type'=>'product_governed_operation','target_id'=>substr(hash('sha256',$token.':'.$id),0,32),'parameters'=>$input]];
    }
    $allowed=array_column($steps,'capability_id');
    $operations=array_map(static fn(array $step):array=>['id'=>$step['id'],'capability_id'=>$step['capability_id'],'parameters'=>$step['input']['parameters']],$steps);
    $proposalService=new ProductGovernedPlanProposalService($db,$goals,$bindings);
    $description='Prepare new assortment, price, receive, reserve, issue and release inventory';
    $secret=str_repeat('q',40);$signatures=new AutomationIntentSignatureVerifier($secret,'',$org);
    $intents=new CanonicalAutomationIntentStore($db,$signatures,null,$proposalService,$capabilities);
    $raw=json_encode(['kind'=>'product_management.governed_plan.v1','description'=>$description,'operations'=>$operations],JSON_THROW_ON_ERROR);
    $ts=(string)time();$nonce=bin2hex(random_bytes(16));
    $sign=static fn(string $body):string=>hash_hmac('sha256',implode("\n",['ai',$ts,$nonce,$body]),$secret);
    $deny(fn()=>$intents->submitProduct('ai',$ts,$nonce,str_repeat('0',64),$raw),'Invalid AI signature');
    $machine=$intents->submitProduct('ai',$ts,$nonce,$sign($raw),$raw);
    $assert($machine['state']==='pending_review'&&$machine['execution_authority']==='none','Machine intake only');
    $assert($intents->submitProduct('ai',$ts,$nonce,$sign($raw),$raw)['intent_id']===$machine['intent_id'],'Machine nonce replay');
    $changed=$operations;$changed[0]['parameters']['quantity']='9';
    $altered=json_encode(['kind'=>'product_management.governed_plan.v1','description'=>$description,'operations'=>$changed],JSON_THROW_ON_ERROR);
    $deny(fn()=>$intents->submitProduct('ai',$ts,$nonce,$sign($altered),$altered),'Conflicting signed nonce');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM cos_federation_plans WHERE organization_id=?',[$org])===0,'Machine intake created Plan before manager adoption');
    $inspected=$intents->inspect($actor,$machine['intent_id']);
    $assert($inspected['kind']==='product'&&count($inspected['operations'])===19,'Human review exact signed proposal');
    $deny(fn()=>$intents->adopt($actor,$machine['intent_id'],str_repeat('0',64)),'Wrong reviewed digest');
    $adopted=$intents->adopt($actor,$machine['intent_id'],$machine['digest']);
    $goalId=$adopted['goal_id'];$planId=$adopted['plan_id'];
    $assert($intents->inspect($actor,$machine['intent_id'])['adopted_plan_id']===$planId,'Intent linked to canonical Plan');
    $deny(fn()=>$intents->adopt($actor,$machine['intent_id'],$machine['digest']),'Repeated adoption');
    $proposalKey=ProductGovernedPlanProposalService::intentKey($machine['intent_id']);
    $proposal=$proposalService->propose($actor,$proposalKey,$description,$operations);
    $assert($proposal['plan_id']===$planId&&$proposal['operation_count']===19&&$proposal['approval_required']&&!$proposal['execution_started'],'Adopted proposal replay');
    $deny(fn()=>$proposalService->propose($actor,$proposalKey,$description,$changed),'Changed approved proposal');
    $bad=$operations;$bad[0]['parameters']['organization_id']='foreign';
    $deny(fn()=>$proposalService->propose($actor,bin2hex(random_bytes(16)),$description,$bad),'Injected tenant');
    $bad=$operations;$bad[0]['capability_id']='product_management.stock.receive';
    $deny(fn()=>$proposalService->propose($actor,bin2hex(random_bytes(16)),$description,$bad),'Direct human capability bypass');
    $assert($products->balance($org,$variant,$loc)['on_hand']==='0.000000'&&$prices->get($org,$variant,'USD')['price']===null,'Proposal must not write business data');
    $deny(fn()=>$bridge->start($actor,$runId,$planId,$planApproval),'Unapproved Plan cannot execute');
    foreach($allowed as $i=>$type){
        $pdo->prepare('INSERT INTO cos_policies
            (id,organization_id,code,name,action_type,conditions,decision,priority,version,status)
            VALUES (:id,:org,:code,"Product independent Action approval",:type,:conditions,"APPROVAL_REQUIRED",1,1,"ACTIVE")')
            ->execute(['id'=>'policy-'.$token.'-'.$i,'org'=>$org,'code'=>'pm-governed-'.$i,'type'=>$type,'conditions'=>'[]']);
    }

    $pdo->prepare('INSERT INTO cos_policies (id,organization_id,code,name,action_type,conditions,decision,priority,version,status) VALUES (?,?,?,?,?,?,"APPROVAL_REQUIRED",1,1,"ACTIVE")')
        ->execute(['policy-plan-'.$token,$org,'pm-plan','Product independent Plan approval',FederationPlanApproveHandler::ACTION_TYPE,'[]']);
    $coordinator=new FederationPlanApprovalCoordinator($db,$goals,$domains,$modules,new GoalPlanApprovalRequestFactory(),$policyService,$policies,$contexts,$engine);
    $approvals=new ApprovalService($approvalRepo,$actionService,$transactions,null,null,new FederationPlanApprovalAuthority($pdo));
    $request=$coordinator->requestApproval($actor,$planId,'pm-plan-'.$token);
    $planApproval=$request['action_id'];
    $assert($request['status']==='PENDING_APPROVAL','Real Plan approval request');
    $planDecision=(string)$db->fetchOne('SELECT id FROM cos_approvals WHERE organization_id=? AND action_id=?',[$org,$planApproval]);
    $assert($planDecision!=='','Plan approval exists');
    $deny(fn()=>$approvals->approve($org,$planDecision,$owner,'self approval'),'Plan owner self approval');
    $deny(fn()=>$bridge->start($actor,$runId,$planId,$planApproval),'Plan pending approval');
    $approvals->approve($org,$planDecision,$reviewer,'Independent Plan approval');
    $assert($actionService->execute($org,$planApproval,'pm-plan-worker')?->status===ActionStatus::Completed,'Real Plan approval worker');
    $assert($db->fetchOne('SELECT state FROM cos_federation_plans WHERE organization_id=? AND plan_id=?',[$org,$planId])==='approved','Approved Plan persisted by handler');
    $assert($bridge->start($actor,$runId,$planId,$planApproval)['execution_authority']==='platform_workflow',
        'Platform Workflow was not sole execution authority');
    $deny(fn()=>$bridge->start($actor,'other-'.$token,$planId,$planApproval),
        'same Product Plan started twice');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='running',
        'canonical Run did not advance to running');
    $actionIds=[];
    foreach($steps as $i=>$step){
        $pending=$bridge->advance($actor,$runId,$planApproval);
        $assert(($pending['state']??null)==='awaiting_human_approval','Independent Action approval missing for '.$step['id']);
        $actionId=$pending['action_id']??null;
        $assert(is_string($actionId)&&!in_array($actionId,$actionIds,true),'Unique canonical Action per Step');
        $actionIds[]=$actionId;
        $assert($actionService->execute($org,$actionId,'pm-premature-worker')===null,'Pending Action executed');
        $assert($bridge->advance($actor,$runId,$planApproval)['action_id']===$actionId,'Duplicate dispatch');
        $approvalId=(string)$db->fetchOne('SELECT id FROM cos_approvals WHERE organization_id=? AND action_id=?',[$org,$actionId]);
        $assert($approvalId!=='','Action approval exists');
        $deny(fn()=>$approvals->approve($org,$approvalId,$owner,'self approval'),'Action owner self approval');
        $approvals->approve($org,$approvalId,$reviewer,'Independent Action approval');
        $assert($actionService->execute($org,$actionId,'pm-governed-worker')?->status===ActionStatus::Completed,'Approved Action failed: '.$step['id']);
        $assert($actionService->execute($org,$actionId,'pm-replay-worker')===null,'Action executed twice');
        $assert($bridge->advance($actor,$runId,$planApproval)['state']==='step_completed','Step checkpoint failed: '.$step['id']);
        if($i===0)$assert($products->balance($org,$variant,$loc)['on_hand']==='10.000000'&&$prices->get($org,$variant,'USD')['price']===null,'First effect and later step isolation');
    }
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='completed','Workflow completion');
    $balance=$products->balance($org,$variant,$loc);
    $assert($balance['on_hand']==='6.000000'&&$balance['reserved']==='3.000000'&&$balance['available']==='3.000000','Business stock result');
    $assert($prices->get($org,$variant,'USD')['price']==='125.5000','Business price result');
    $assert($products->balance($org,$variant,$destination)['on_hand']==='2.000000','Destination stock result');
    $assert($prices->getListPrice($org,$listId,$variant)['price']==='99.2500','Business list price result');
    $assert(count($products->getProduct($org,$product['product_id'])['variants'])===3,'Matrix generated two distinct SKU');
    $assert(count($products->searchProducts($org,'NEW'.strtoupper($token)))===1,'New Product exists');
    $attributes=array_column($products->productAttributes($org,$product['product_id'])['items'],'value','code');
    $assert($attributes['notes']==='QA validated','Bulk attribute effect');
    $assert($products->balance($org,$second['variant_id'],$loc)['reserved']==='0.000000','Reservation released');
    $eval=$goals->recordEvaluation($actor,'eval-'.$token,$runId);
    $assert($eval['result']==='satisfied'&&($eval['criteria'][0]['observed']??null)===19,'Independent native business Goal outcome');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM cos_actions WHERE organization_id=? AND type LIKE ?',[$org,'product_management.governed.%' ])===19,'Exactly nineteen canonical business Actions');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM cos_federation_runs WHERE organization_id=?',[$org])===0,'No legacy executor');
    $foreign=new TenantContext(UserId::fromString($owner),OrganizationId::fromString('foreign-'.$token),OrganizationRole::fromString('manager'),[Permission::fromString(TenantPermissions::MANAGE)]);
    $deny(fn()=>$outcome->observeRun($foreign,$runId,ProductGovernedRunOutcomeProvider::CRITERION,new DateTimeImmutable('-1 day'),new DateTimeImmutable('+1 day')),'Foreign tenant evidence');
    $assert($products->getProduct($org,$lifecycle['product-update']['product_id'])['name']==='Approved rename','Product update readback');
    $assert($products->getProduct($org,$lifecycle['product-archive']['product_id'])['status']==='archived','Product archive readback');
    $db->executeStatement('UPDATE pm_variants SET barcode=? WHERE organization_id=? AND variant_id=?',['FORGED',$org,$lifecycle['variant-update']['variants'][0]['variant_id']]);
    $deny(fn()=>$goals->recordEvaluation($actor,'lifecycle-tampered-'.$token,$runId),'Variant update tampering');
    $db->executeStatement('UPDATE pm_variants SET barcode=? WHERE organization_id=? AND variant_id=?',['QA123',$org,$lifecycle['variant-update']['variants'][0]['variant_id']]);
    $catalogReadback=$products->getProduct($org,$catalogSeed['product_id']);
    $assert(count($catalogReadback['variants'])===2&&count($catalogReadback['categories'])===1&&count($catalogReadback['translations'])===1,'Catalog native effects');
    $db->executeStatement('UPDATE pm_product_translations SET name=? WHERE organization_id=? AND product_id=? AND locale=?',['FORGED',$org,$catalogSeed['product_id'],'uk']);
    $deny(fn()=>$goals->recordEvaluation($actor,'translation-tampered-'.$token,$runId),'Translation tampering');
    $db->executeStatement('UPDATE pm_product_translations SET name=? WHERE organization_id=? AND product_id=? AND locale=?',['Approved translation',$org,$catalogSeed['product_id'],'uk']);
    // Independently invalidate price history, then restore it and invalidate a transfer leg.
    $db->executeStatement('UPDATE pm_price_list_history SET new_amount=? WHERE organization_id=? AND list_id=? AND variant_id=?',['100.0000',$org,$listId,$variant]);
    $deny(fn()=>$goals->recordEvaluation($actor,'list-tampered-'.$token,$runId),'List price history tampering');
    $db->executeStatement('UPDATE pm_price_list_history SET new_amount=? WHERE organization_id=? AND list_id=? AND variant_id=?',['99.2500',$org,$listId,$variant]);
    $db->executeStatement('UPDATE pm_stock_movements SET source_ref=? WHERE organization_id=? AND variant_id=? AND location_id=?',['FORGED',$org,$variant,$destination]);
    $deny(fn()=>$goals->recordEvaluation($actor,'transfer-tampered-'.$token,$runId),'Paired transfer tampering');
    // Deliberately alter only fixture native ledger, preserving technical Action success.
    $db->executeStatement('UPDATE pm_stock_movements SET source_ref=? WHERE organization_id=? AND variant_id=?',['FORGED',$org,$variant]);
    $deny(fn()=>$goals->recordEvaluation($actor,'tampered-'.$token,$runId),'Technical success without intact native evidence');
    echo "Product Management governed nineteen-step business Goal/Plan/Action/Outcome native MySQL E2E PASS.\n";
} finally {
    if($oldRollout===false)putenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
    else putenv('COS_FEDERATION_CANONICAL_ORGANIZATION='.$oldRollout);
}
