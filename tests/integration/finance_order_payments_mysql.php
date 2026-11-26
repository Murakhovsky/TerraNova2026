<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require getenv('COS_TEST_AUTOLOAD')?:$root.'/vendor/autoload.php';
use Domains\Finance\Infrastructure\Persistence\MySql\MysqlCommerceOrderPaymentStore;
use Domains\Finance\Application\Contract\FinanceOrderExecutionAuthority;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOrderRepository;
use Kernel\Module\{ActiveModuleResolver,ModuleCatalog,ModuleDefinition};
use Kernel\Module\Contract\ModuleStateRepositoryInterface;

$check=static function(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);};
$deny=static function(callable $fn):void{try{$fn();}catch(DomainException){return;}throw new RuntimeException('Invalid Finance order operation accepted.');};
$db=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',getenv('DB_HOST')?:'127.0.0.1',(int)(getenv('DB_PORT')?:3306),getenv('DB_DATABASE')?:'cos'),getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$org='fin-order-'.bin2hex(random_bytes(5));$id=bin2hex(random_bytes(16));
$catalog=new ModuleCatalog([ModuleDefinition::fromArray(require $root.'/app/Domains/Sales/module.php'),ModuleDefinition::fromArray(require $root.'/app/Domains/Finance/module.php'),ModuleDefinition::fromArray(require $root.'/app/Domains/ProductManagement/module.php'),ModuleDefinition::fromArray((static fn()=>require $root.'/app/Domains/Marketing/module.php')()),ModuleDefinition::fromArray((static fn()=>require $root.'/app/Domains/Federation/module.php')())]);
$states=new class implements ModuleStateRepositoryInterface{
    public bool $enabled=true;
    public function enabledOverride(string $organizationId,string $moduleId):?bool{return $this->enabled;}
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void{$this->enabled=$enabled;}
};
$active=new ActiveModuleResolver($catalog,$states);
$sales=new MysqlSalesOrderRepository($db);
$authority=new class implements FinanceOrderExecutionAuthority {
    public bool $allowed=true;
    public function assertApproved(string $organizationId,string $orderId,string $actionType,array $parameters,string $requester,string $actionId,string $correlationId,string $key):void
    {if(!$this->allowed)throw new DomainException('Test approval denied.');}
};
$repo=new MysqlCommerceOrderPaymentStore($db,$sales,$active,$authority);
$line=['line_no'=>1,'sku'=>'TEST','title'=>'Order fixture','product_id'=>null,'variant_id'=>null,'quantity'=>1,'unit_price'=>'100.00','subtotal'=>'100.00'];
$placed=['schema_version'=>'1.0.0','event_id'=>bin2hex(random_bytes(16)),'event_type'=>'sales.commerce.order.placed','organization_id'=>$org,'order_id'=>$id,'deal_id'=>null,'revision'=>1,'occurred_at'=>'2026-11-25T00:00:00Z','channel'=>'native','store_reference'=>'manual','external_order_id'=>$id,'currency'=>'EUR','order_total'=>'100.00','financial_evidence_reference'=>null,'realized_revenue'=>null,'attribution_observations'=>(object)[],'attribution_status'=>'unresolved','provenance'=>'native_operator','correlation_id'=>'fixture'];
$db->beginTransaction();
$sales->create($org,['order_id'=>$id,'owner_id'=>101,'deal_id'=>null,'customer_id'=>null,'channel'=>'native','store_reference'=>'manual','external_order_id'=>$id,'currency'=>'EUR','subtotal'=>'100.00','discount'=>'0.00','tax'=>'0.00','tax_included'=>false,'shipping'=>'0.00','total'=>'100.00','attribution'=>[],'placed_at'=>'2026-11-25 00:00:00','created_by'=>101],[$line],$placed);
$db->commit();
$reg=static fn(array $p,string $key)=>$repo->register($org,$id,['order_id'=>$id,...$p],'101','action-'.$key,'trace-'.$key,$key);
$reconcile=static fn(array $p,string $key,string $actor='102')=>$repo->reconcile($org,$id,['order_id'=>$id,...array_diff_key($p,['parent_id'=>true])],$actor,'action-'.$key,'trace-'.$key,$key);
$base=['currency'=>'EUR','source_account'=>'bank-1'];
$first=[...$base,'kind'=>'capture','amount'=>'40.00','source_reference'=>'cap-1'];
$authority->allowed=false;$deny(fn()=>$reg($first,'denied'));$authority->allowed=true;
$check($repo->financialEvidence($org,$id)===null,'Denied mutation produced evidence.');
$a=$reg($first,'register-1');
$check($a===$reg($first,'register-1'),'Registration replay changed receipt.');
$deny(fn()=>$reg([...$first,'amount'=>'41.00'],'register-1'));
$deny(fn()=>$reg($first,'duplicate-source'));
$check($repo->financialEvidence($org,$id)===null,'Pending registration counted as collected.');
$check($repo->operations($org,$id,0,1)['operations'][0]['state']==='pending_registration','Pending worklist missing.');
$c1=[...$first,'operation_id'=>$a['finance_reference'],'statement_reference'=>'statement-1','expected_revision'=>0];
$deny(fn()=>$reconcile($c1,'self','101'));
$deny(fn()=>$reconcile([...$c1,'amount'=>'39.00'],'mismatch'));
$deny(fn()=>$reconcile([...$c1,'currency'=>'USD'],'wrong-currency'));
$e=$reconcile($c1,'reconcile-1');
$check($e===$reconcile($c1,'reconcile-1'),'Reconciliation replay changed receipt.');
$check($repo->financialEvidence($org,$id)['outstanding_amount']==='60.00','Partial collection balance invalid.');
$second=[...$base,'kind'=>'capture','amount'=>'60.00','source_reference'=>'cap-2'];
$b=$reg($second,'register-2');
$c2=[...$second,'operation_id'=>$b['finance_reference'],'statement_reference'=>'statement-2','expected_revision'=>1];
$deny(fn()=>$reconcile([...$c2,'expected_revision'=>0],'stale'));
$deny(fn()=>$reconcile([...$c2,'statement_reference'=>'statement-1'],'duplicate-statement'));
$reconcile($c2,'reconcile-2');
$check($repo->financialEvidence($org,$id)['net_collected_amount']==='100.00','Full collection missing.');
$refund=[...$base,'kind'=>'refund','amount'=>'15.00','source_reference'=>'refund-1','parent_id'=>$a['finance_reference']];
$f=$reg($refund,'register-refund');$reconcile([...$refund,'operation_id'=>$f['finance_reference'],'statement_reference'=>'statement-3','expected_revision'=>2],'reconcile-refund');
$dispute=[...$base,'kind'=>'chargeback','amount'=>'20.00','source_reference'=>'dispute-1','parent_id'=>$b['finance_reference']];
$d=$reg($dispute,'register-dispute');$reconcile([...$dispute,'operation_id'=>$d['finance_reference'],'statement_reference'=>'statement-4','expected_revision'=>3],'reconcile-dispute');
$over=[...$base,'kind'=>'refund','amount'=>'26.00','source_reference'=>'refund-over','parent_id'=>$a['finance_reference']];
$o=$reg($over,'register-over');$deny(fn()=>$reconcile([...$over,'operation_id'=>$o['finance_reference'],'statement_reference'=>'statement-over','expected_revision'=>4],'reconcile-over'));
$rev=[...$base,'kind'=>'chargeback_reversal','amount'=>'5.00','source_reference'=>'reversal-1','parent_id'=>$d['finance_reference']];
$v=$reg($rev,'register-reversal');$reconcile([...$rev,'operation_id'=>$v['finance_reference'],'statement_reference'=>'statement-5','expected_revision'=>4],'reconcile-reversal');
$fact=$repo->financialEvidence($org,$id);
$check($fact['revision']===5&&$fact['captured_amount']==='100.00'&&$fact['refunded_amount']==='15.00'&&$fact['chargeback_amount']==='15.00'&&$fact['net_collected_amount']==='70.00'&&$fact['outstanding_amount']==='30.00','Ledger projection drift.');
$check($repo->operations($org,$id,0,2)['has_more']===true,'Operations pagination missing.');
$check($repo->financialEvidence('foreign-tenant',$id)===null,'Cross tenant finance evidence.');
$deny(fn()=>$repo->register('foreign-tenant',$id,$first,'101','a','t','k'));
$deny(fn()=>$repo->operations('foreign-tenant',$id,0,25));
$states->enabled=false;$check($repo->financialEvidence($org,$id)===null,'Inactive Finance leaked evidence.');$deny(fn()=>$reg($first,'inactive'));
$states->enabled=true;
$check($sales->placement($org,$id)['realized_revenue']===null&&$sales->get($org,$id)['revision']===1,'Finance rewrote Sales terms or lifecycle.');
echo "Finance native order ledger MySQL partial capture, refund, chargeback, replay, tenant and source fencing PASS; approval authority substituted only in this store-level test.\n";

/* Two physical connections: an existing consistent-read snapshot must not
 * permit a second refund to overdraw one capture even when order-wide funds
 * are sufficient. The second connection commits before the first takes
 * the balance lock, reproducing the stale-snapshot edge deterministically. */
$db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db2=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',getenv('DB_HOST')?:'127.0.0.1',(int)(getenv('DB_PORT')?:3306),getenv('DB_DATABASE')?:'cos'),getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$repo2=new MysqlCommerceOrderPaymentStore($db2,new MysqlSalesOrderRepository($db2),$active,$authority);
$refundB=[...$base,'kind'=>'refund','amount'=>'20.00','source_reference'=>'race-refund-b','parent_id'=>$a['finance_reference']];
$refundA=[...$base,'kind'=>'refund','amount'=>'10.00','source_reference'=>'race-refund-a','parent_id'=>$a['finance_reference']];
$opB=$reg($refundB,'race-register-b');
$opA=$reg($refundA,'race-register-a');
$payloadB=['order_id'=>$id,...array_diff_key($refundB,['parent_id'=>true]),'operation_id'=>$opB['finance_reference'],'statement_reference'=>'race-statement-b','expected_revision'=>5];
$payloadA=['order_id'=>$id,...array_diff_key($refundA,['parent_id'=>true]),'operation_id'=>$opA['finance_reference'],'statement_reference'=>'race-statement-a','expected_revision'=>6];
$snapshotHook=static function()use($db,$repo2,$org,$id,$payloadB,$check):void{
    $q=$db->prepare('SELECT revision FROM cos_finance_order_balances WHERE organization_id=? AND order_id=?');
    $q->execute([$org,$id]);$check((int)$q->fetchColumn()===5,'Expected pre-race snapshot.');
    $q->closeCursor();
    $repo2->reconcile($org,$id,$payloadB,'102','race-action-b','race-trace-b','race-reconcile-b');
};
$snapshotAuthority=new class($snapshotHook) implements FinanceOrderExecutionAuthority{
    public function __construct(private Closure $hook){}
    public function assertApproved(string $organizationId,string $orderId,string $actionType,array $parameters,string $requester,string $actionId,string $correlationId,string $key):void
    {($this->hook)();}
};
$snapshotRepo=new MysqlCommerceOrderPaymentStore($db,$sales,$active,$snapshotAuthority);
try{
    $snapshotRepo->reconcile($org,$id,$payloadA,'102','race-action-a','race-trace-a','race-reconcile-a');
    throw new RuntimeException('Stale snapshot admitted a parent over-refund.');
}catch(DomainException $e){
    $check($e->getMessage()==='Operation exceeds confirmed parent balance.','Unexpected race denial: '.$e->getMessage());
}
$afterRace=$repo->financialEvidence($org,$id);
$check($afterRace['revision']===6&&$afterRace['refunded_amount']==='35.00'&&$afterRace['net_collected_amount']==='50.00','Race rejection altered committed collection.');
$q=$db->prepare('SELECT COUNT(*) FROM cos_finance_order_reconciliations WHERE organization_id=? AND operation_id=?');
$q->execute([$org,$opA['finance_reference']]);
$check((int)$q->fetchColumn()===0,'Rejected refund produced reconciliation evidence.');
$q->closeCursor();
echo "Finance two-connection parent exposure stale-snapshot fence PASS\n";

/* Real persisted Kernel approval path. No replacement authority or payment provider. */
$org='fin-auth-'.bin2hex(random_bytes(5));$id=bin2hex(random_bytes(16));
$db->prepare('INSERT INTO cos_organizations (id,name,status) VALUES (?,?,"ACTIVE")')->execute([$org,'O4 approval fixture']);
$manager=static function(string $label)use($db,$org):string{
    $db->prepare('INSERT INTO tn_users (organization_id,email,password_hash,full_name,role,status) VALUES (?,?,?,?, "manager","active")')->execute([$org,$label.'-'.$org.'@example.invalid','test-only',$label]);
    $u=(string)$db->lastInsertId();
    $db->prepare('INSERT INTO cos_organization_memberships (organization_id,user_id,role,status) VALUES (?,?,"manager","ACTIVE")')->execute([$org,$u]);return $u;
};
$requester=$manager('requester');$reviewer=$manager('reviewer');
$db->beginTransaction();
$sales->create($org,['order_id'=>$id,'owner_id'=>(int)$requester,'deal_id'=>null,'customer_id'=>null,'channel'=>'native','store_reference'=>'manual','external_order_id'=>$id,'currency'=>'EUR','subtotal'=>'100.00','discount'=>'0.00','tax'=>'0.00','tax_included'=>false,'shipping'=>'0.00','total'=>'100.00','attribution'=>[],'placed_at'=>'2026-11-25 00:00:00','created_by'=>(int)$requester],[$line],[...$placed,'event_id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,'order_id'=>$id,'external_order_id'=>$id]);
$db->commit();
$realAuthority=new \Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinanceOrderApprovalAuthority($db);
$realStore=new MysqlCommerceOrderPaymentStore($db,$sales,$active,$realAuthority);
$handler=new \Domains\Finance\Automation\Action\FinanceOrderPaymentActionHandler($realStore);
$domain=new class($handler) implements \Kernel\Module\DomainModuleInterface,\Kernel\Module\Contract\ActionOwningModuleInterface{
    public function __construct(private \Domains\Finance\Automation\Action\FinanceOrderPaymentActionHandler $handler){}
    public function name():string{return 'finance';}
    public function actionTypes():array{return ['finance.order.payment.register','finance.order.payment.reconcile'];}
    public function actionHandlers():array{return [$this->handler];}
};
$domains=new \Kernel\Module\DomainModuleRegistry([$domain]);
$actions=new \Kernel\Action\Service\ActionService(new \Infrastructure\Platform\Persistence\MySql\Action\MysqlActionRepository($db),
    new \Kernel\Action\Service\ActionExecutor([$handler],new \Kernel\Action\Service\ModuleActionExecutionGate($domains,$active)));
$approvals=new \Infrastructure\Platform\Persistence\MySql\Approval\MysqlApprovalRepository($db);
$tx=new class($db) implements \Kernel\Transaction\Contract\TransactionManagerInterface{
    public function __construct(private PDO $db){}
    public function transactional(callable $operation):mixed{
        if($this->db->inTransaction())return $operation();
        $this->db->beginTransaction();
        try{$out=$operation();$this->db->commit();return $out;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function isActive():bool{return $this->db->inTransaction();}
    public function afterCommit(callable $callback):void{if($this->db->inTransaction())throw new LogicException('Deferred callbacks unsupported in fixture');$callback();}
};
foreach($domain->actionTypes() as $type){
    $db->prepare('INSERT INTO cos_policies (id,organization_id,code,name,action_type,conditions,decision,priority,version,status) VALUES (?,?,?,?,?,?,"APPROVAL_REQUIRED",1,1,"ACTIVE")')->execute([bin2hex(random_bytes(16)),$org,$type,$type,$type,'[]']);
}
$policy=new \Kernel\Policy\Service\ActionPolicyService($actions,new \Infrastructure\Platform\Persistence\MySql\Policy\MysqlPolicyRepository($db),new \Infrastructure\Platform\Persistence\MySql\Policy\MysqlPolicyEvaluationRepository($db),$approvals,new \Kernel\Policy\Service\PolicyEngine(new \Kernel\Rule\Service\ConditionEvaluator()),$tx,null,$domains,$active);
$approvalService=new \Kernel\Approval\Service\ApprovalService($approvals,$actions,$tx,null,null,$realAuthority);
$payload=['order_id'=>$id,'kind'=>'capture','amount'=>'40.00','currency'=>'EUR','source_account'=>'test-bank','source_reference'=>'approved-capture'];
$deny(fn()=>$realStore->register($org,$id,$payload,$requester,'missing','missing','missing'));
$submit=static function(string $type,array $p,string $actor,string $other,string $key)use($org,$id,$policy,$approvalService,$actions,$db,$check,$deny){
    $a=$policy->submit($org,new \Kernel\Action\ActionProposal($type,'sales_order',$id,$p,'USER',$actor,'APPROVAL_REQUIRED','HIGH',$key),'trace-'.$key);
    $check($a->status===\Kernel\Action\ActionStatus::PendingApproval,'Finance Action not pending approval.');
    $q=$db->prepare('SELECT id FROM cos_approvals WHERE organization_id=? AND action_id=?');$q->execute([$org,$a->id]);$approval=(string)$q->fetchColumn();
    $check($approval!=='','Approval receipt missing.');
    $deny(fn()=>$approvalService->approve($org,$approval,$actor));
    $check($actions->executeOutcome($org,$a->id,'o4-worker')===null,'Unapproved Action claimed.');
    $approvalService->approve($org,$approval,$other);
    $out=$actions->executeOutcome($org,$a->id,'o4-worker');
    $check($out!==null&&$out->result->successful&&$out->action()->status===\Kernel\Action\ActionStatus::Completed,'Approved native Finance Action failed.');
    return $out->result->data;
};
$registered=$submit('finance.order.payment.register',$payload,$requester,$reviewer,'real-register');
$check($realStore->financialEvidence($org,$id)===null,'Unreconciled approved registration is not cash.');
$submit('finance.order.payment.reconcile',[...$payload,'operation_id'=>$registered['finance_reference'],'statement_reference'=>'bank-statement-line','expected_revision'=>0],$reviewer,$requester,'real-reconcile');
$check($realStore->financialEvidence($org,$id)['net_collected_amount']==='40.00','Reconciled Action missing collection.');
echo "Finance persisted Kernel Policy/Approval/Action + Sales order MySQL acceptance PASS (native Action path, not Platform Workflow admission).\n";


/* Canonical Federation/Workflow integration: real Goal, Plan, independent Plan and Action
 * approvals, worker gate, native business readback and persisted evaluation. */
$wfDb=\Doctrine\DBAL\DriverManager::getConnection([
    'driver'=>'pdo_mysql','host'=>getenv('DB_HOST')?:'127.0.0.1','port'=>(int)(getenv('DB_PORT')?:3306),
    'dbname'=>getenv('DB_DATABASE')?:'cos','user'=>getenv('DB_USERNAME')?:'cos',
    'password'=>getenv('DB_PASSWORD')?:'','charset'=>'utf8mb4',
]);
$capabilities=new \Kernel\Module\CanonicalCapabilityCatalog($catalog);
$db->prepare('INSERT INTO cos_policies (id,organization_id,code,name,action_type,conditions,decision,priority,version,status) VALUES (?,?,?,?,?,?,"APPROVAL_REQUIRED",1,1,"ACTIVE")')
    ->execute([bin2hex(random_bytes(16)),$org,'order-plan-approval','Independent Plan approval','cos.federation.plan.approval','[]']);
$verifiedR1=[];
$executeWorkflow=static function(string $type,array $p,string $initiator,string $approver,?array $config=null)use(
    $wfDb,$db,$org,$id,$catalog,$capabilities,$active,$domains,$handler,$approvals,$tx,$realAuthority,$check,$deny,$sales,$realStore,&$verifiedR1
):array{
    $ownerDomain=$config['owner']??'finance';$effect=$config['effect']??'financial';
    $targetType=$config['target_type']??'sales_order';$targetId=$config['target']??$id;
    $registry=$config['domains']??$domains;$handlers=$config['handlers']??[$handler];
    $approvalAuthority=$config['approval']??$realAuthority;
    $token=bin2hex(random_bytes(8));
    $actor=new \Kernel\Tenant\Model\TenantContext(\Kernel\Shared\Domain\UserId::fromString($initiator),
        \Kernel\Shared\Domain\OrganizationId::fromString($org),\Kernel\Identity\Model\OrganizationRole::fromString('manager'),
        [\Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::ACCESS),
         \Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::MANAGE)]);
    $planReader=new \App\Persistence\Federation\FederationPlanApprovalEvidenceReader($wfDb);
    $planHandler=new \App\Persistence\Federation\FederationPlanApproveHandler($wfDb,$planReader);
    $registry=new \Kernel\Module\DomainModuleRegistry([...$registry->modules(),new \Domains\Federation\Bootstrap\FederationDomainModule($planHandler)]);
    $planEvidence=new \App\Persistence\Workflow\FederationWorkflowPlanApprovalEvidence($wfDb,$planReader);
    $receipt=new \App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier($wfDb,$planEvidence);
    $claimGate=new \App\Persistence\Workflow\DoctrineWorkflowActionClaimAdmission($wfDb,$planEvidence,$capabilities,$active);
    $store=new \App\Persistence\Workflow\DoctrineWorkflowExecutionStore($wfDb,$receipt,$claimGate);
    $policies=new \Infrastructure\Platform\Persistence\MySql\Policy\MysqlPolicyRepository($db);
    $engine=new \Kernel\Policy\Service\PolicyEngine(new \Kernel\Rule\Service\ConditionEvaluator());
    $contexts=new \Kernel\Policy\Service\PolicyContextBuilder($registry);
    $nonActions=new \App\Persistence\Workflow\FailClosedWorkflowNonActionReceiptEvidence();
    $worker=new \App\Persistence\Workflow\DoctrineWorkflowActionWorkerAdmission($wfDb,$planEvidence,$capabilities,$active,
        new \App\Persistence\Workflow\WorkflowPredecessorReceiptVerifier($receipt,$nonActions),$policies,$engine,$contexts,
        new \App\Infrastructure\Persistence\MySql\MysqlIdentityRepository($db),
        new \Kernel\Tenant\Service\TenantContextFactory(new \App\Security\RoleTenantPermissionResolver()));
    $actions=new \Kernel\Action\Service\ActionService(new \Infrastructure\Platform\Persistence\MySql\Action\MysqlActionRepository($db),
        new \Kernel\Action\Service\ActionExecutor([...$handlers,$planHandler],new \Kernel\Action\Service\ModuleActionExecutionGate($registry,$active,null,$worker)));
    $policy=new \Kernel\Policy\Service\ActionPolicyService($actions,$policies,
        new \Infrastructure\Platform\Persistence\MySql\Policy\MysqlPolicyEvaluationRepository($db),$approvals,$engine,$tx,null,$registry,$active,$contexts);
    $approvalService=new \Kernel\Approval\Service\ApprovalService($approvals,$actions,$tx,null,null,
        new \App\Infrastructure\Automation\CompositeActionApprovalAuthority(
            new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesApprovalAuthority($db),
            new \App\Infrastructure\Automation\FederationPlanApprovalAuthority($db),$realAuthority));
    $outcomes=new \App\Persistence\Federation\FederationTrustedOutcomeEvidenceResolver($active,[
        new \App\Persistence\Sales\SalesOrderRunOutcomeProvider($wfDb,$sales,$sales,new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesMutationReceiptRepository($db),$receipt),
        new \App\Persistence\Finance\FinanceOrderRunOutcomeProvider($wfDb,$realStore,$receipt),
        ...(isset($config['outcome_factory'])?[$config['outcome_factory']($receipt)]:[])]);
    $goals=new \App\Persistence\Federation\FederationGoalStore($wfDb,
        new \App\Persistence\Federation\FederationCapabilityBindingResolver($capabilities,$registry,$active),
        new \Platform\Orchestration\Goal\GoalPlanValidator($capabilities),new \Platform\Orchestration\Goal\GoalOutcomeEvaluator(),$outcomes,$planReader);
    $scheduler=new \Platform\Workflow\Service\WorkflowStepScheduler();
    $bridge=new \App\Persistence\Federation\FederationCanonicalWorkflowBridge($wfDb,$store,$scheduler,$goals,
        $planEvidence,$receipt,$nonActions,$planReader,$capabilities,$registry,$active,$policies,$engine,$contexts,$policy,
        new \App\Persistence\Workflow\DoctrineWorkflowExecutionCoordinator($wfDb,$store,$scheduler,$planEvidence,$receipt));
    $goal='goal-'.$token;$plan='plan-'.$token;$run='fin-wf-'.$token;
    $steps=[['id'=>'payment','capability_id'=>$type,'capability_version'=>'1.0.0',
        'input'=>['target_type'=>$targetType,'target_id'=>$targetId,'parameters'=>$p]],...($config['extra_steps']??[])];
    $criteria=[];$allowed=[];
    foreach($steps as $step){
        $cap=$step['capability_id'];$allowed[]=$cap;
        $criterion=in_array($cap,\Domains\Sales\Automation\Action\SalesGovernedBusinessOperations::types(),true)
            ?\App\Persistence\Sales\SalesBusinessRunOutcomeProvider::CRITERION
            :(str_starts_with($cap,'sales.')?\App\Persistence\Sales\SalesOrderRunOutcomeProvider::CRITERION:\App\Persistence\Finance\FinanceOrderRunOutcomeProvider::CRITERION);
        $criteria[$criterion]=($criteria[$criterion]??0)+1;
    }
    $specCriteria=[];foreach($criteria as $criterion=>$count)$specCriteria[]=['id'=>$criterion,'operator'=>'at_least','expected'=>$count];
    if(isset($config['intent_origin'])){
        $origin=$config['intent_origin'];$secretAi=str_repeat('a',40);$secretCron=str_repeat('c',40);
        $signatureVerifier=new \App\Application\Capability\AutomationIntentSignatureVerifier($secretAi,$secretCron,$org);
        $access=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAccessControl($db);
        $scope=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesWorkspaceScope($db,$access);
        $planner=new \App\Persistence\Sales\SalesGovernedPlanProposalService($wfDb,$goals,
            new \App\Persistence\Federation\FederationCapabilityBindingResolver($capabilities,$registry,$active),$capabilities,$access,$scope);
        $intents=new \App\Persistence\Capability\CanonicalAutomationIntentStore($wfDb,$signatureVerifier,null,null,$capabilities,$planner);
        $envelope=['kind'=>\App\Persistence\Sales\SalesGovernedPlanProposalService::KIND,'description'=>'Verify native order operations',
            'operations'=>array_map(static fn(array $step):array=>['id'=>$step['id'],'capability_id'=>$step['capability_id'],'parameters'=>$step['input']['parameters']],$steps)];
        $raw=json_encode($envelope,JSON_THROW_ON_ERROR);$stamp=(string)time();
        $sign=static fn(string $body)=>hash_hmac('sha256',implode("\n",[$origin,$stamp,$token,$body]),$origin==='ai'?$secretAi:$secretCron);
        $businessCounts=static fn()=>array_map(static fn(string $table)=>(int)$wfDb->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE organization_id=?',[$org]),
            ['cos_actions','tn_leads','sales_orders','sales_order_sync_runs']);
        $before=$businessCounts();
        $goalCount=(int)$wfDb->fetchOne('SELECT COUNT(*) FROM cos_federation_goals WHERE organization_id=?',[$org]);
        $deny(fn()=>$intents->submitSales($origin,$stamp,$token,str_repeat('0',64),$raw));
        $machineController=new \App\Http\Api\V1\Controller\CanonicalAutomationIntentController($intents);
        $signedRequest=\Symfony\Component\HttpFoundation\Request::create('/api/v1/capability-intents/sales-business','POST',[],[],[],[
            'CONTENT_TYPE'=>'application/json','HTTP_X_COS_AUTOMATION_ORIGIN'=>$origin,'HTTP_X_COS_AUTOMATION_TIMESTAMP'=>$stamp,
            'HTTP_X_COS_AUTOMATION_NONCE'=>$token,'HTTP_X_COS_AUTOMATION_SIGNATURE'=>$sign($raw)],$raw);
        $unsignedRequest=clone $signedRequest;$unsignedRequest->headers->remove('X-COS-Automation-Signature');
        $check($machineController->submitSales($unsignedRequest)->getStatusCode()===403,'Unsigned Sales HTTP ingress accepted.');
        $machineResponse=$machineController->submitSales($signedRequest);
        $check($machineResponse->getStatusCode()===202,'Signed Sales HTTP ingress refused.');
        $submitted=json_decode((string)$machineResponse->getContent(),true,16,JSON_THROW_ON_ERROR);
        $check($submitted['state']==='pending_review'&&$submitted['execution_authority']==='none'&&$businessCounts()===$before
            &&(int)$wfDb->fetchOne('SELECT COUNT(*) FROM cos_federation_goals WHERE organization_id=?',[$org])===$goalCount,
            'Machine intent created Goal or executed native effect before review.');
        $replay=$intents->submitSales($origin,$stamp,$token,$sign($raw),$raw);
        $check($replay['replayed']&&$replay['intent_id']===$submitted['intent_id'],'Signed intent replay duplicated.');
        $changed=json_encode([...$envelope,'description'=>'Changed machine proposal'],JSON_THROW_ON_ERROR);
        $deny(fn()=>$intents->submitSales($origin,$stamp,$token,$sign($changed),$changed));
        $unsupported=json_encode([...$envelope,'operations'=>[['id'=>'unsafe','capability_id'=>'sales.lead.create','parameters'=>$p]]],JSON_THROW_ON_ERROR);
        $deny(fn()=>$intents->submitSales($origin,$stamp,$token,$sign($unsupported),$unsupported));
        $unprivileged=new \Kernel\Tenant\Model\TenantContext(\Kernel\Shared\Domain\UserId::fromString($approver),
            \Kernel\Shared\Domain\OrganizationId::fromString($org),\Kernel\Identity\Model\OrganizationRole::fromString('manager'),
            [\Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::ACCESS),
             \Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::MANAGE)]);
        $deny(fn()=>$intents->inspectSales($unprivileged,$submitted['intent_id']));
        $deny(fn()=>$intents->adoptSales($unprivileged,$submitted['intent_id'],$submitted['digest']));
        $check(in_array($submitted['intent_id'],array_column($intents->pendingSales($actor),'intent_id'),true)
            &&!in_array($submitted['intent_id'],array_column($intents->pending($actor),'intent_id'),true),'Sales intent review was not isolated from legacy HTML.');
        $review=$intents->inspectSales($actor,$submitted['intent_id']);
        $check($review['digest']===$submitted['digest']&&$review['origin']===$origin&&count($review['operations'])===count($steps),'Signed Sales intent readback mismatch.');
        $deny(fn()=>$intents->adoptSales($actor,$submitted['intent_id'],str_repeat('0',64)));
        $deny(fn()=>$intents->adopt($actor,$submitted['intent_id'],$review['digest']));
        $tenantProvider=new class($actor) implements \Kernel\Tenant\Contract\TenantContextProviderInterface{
            public function __construct(private \Kernel\Tenant\Model\TenantContext $actor){}
            public function current():?\Kernel\Tenant\Model\TenantContext{return $this->actor;}
        };
        $reviewController=new \App\Http\Api\V1\Controller\SalesAutomationIntentReviewController($intents,$tenantProvider,new \App\Security\SessionCsrfValidator());
        $check($reviewController->index()->getStatusCode()===200&&$reviewController->inspect($submitted['intent_id'])->getStatusCode()===200,'Sales review HTTP projection unavailable.');
        $reviewRequest=\Symfony\Component\HttpFoundation\Request::create('/api/v1/capability-intents/sales-business/review/'.$submitted['intent_id'].'/adopt','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],
            json_encode(['digest'=>$review['digest']],JSON_THROW_ON_ERROR));
        $session=new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
        $session->set('cos_csrf_token',$token);$reviewRequest->setSession($session);
        $check($reviewController->adopt($reviewRequest,$submitted['intent_id'])->getStatusCode()===400,'Missing review CSRF accepted.');
        $reviewRequest->headers->set('X-CSRF-Token',$token);
        $reviewResponse=$reviewController->adopt($reviewRequest,$submitted['intent_id']);
        $check($reviewResponse->getStatusCode()===201,'Reviewed Sales HTTP proposal refused.');
        $adopted=json_decode((string)$reviewResponse->getContent(),true,16,JSON_THROW_ON_ERROR);
        $goal=$adopted['goal_id'];$plan=$adopted['plan_id'];
        $check($adopted['state']==='adopted'&&$adopted['approval_required']===true&&$adopted['execution_started']===false
            &&$businessCounts()===$before,'Human review improperly executed an Action.');
        $deny(fn()=>$intents->adoptSales($actor,$submitted['intent_id'],$review['digest']));
        $pinned=json_decode((string)$wfDb->fetchOne('SELECT plan_json FROM cos_federation_plans WHERE organization_id=? AND plan_id=?',[$org,$plan]),true,64,JSON_THROW_ON_ERROR);
        $prior=null;
        foreach($pinned['steps'] as $i=>$step){
            $check($step['id']===$steps[$i]['id']&&($step['depends_on']??null)===($prior===null?[]:[$prior]),'Signed Sales plan lost predecessor ordering.');
            $prior=$step['id'];
        }
    }else{
        $goals->createGoal($actor,new \Platform\Orchestration\Goal\GoalSpecification($goal,$org,$initiator,'Verify native order operations',
            $specCriteria,array_values(array_unique($allowed)),1,null,null,'',[],['independent_plan','independent_action'],'always'));
        $goals->proposePlan($actor,$plan,$goal,$steps);
    }
    $coordinator=new \App\Persistence\Federation\FederationPlanApprovalCoordinator($wfDb,$goals,$registry,$active,
        new \Platform\Orchestration\Goal\GoalPlanApprovalRequestFactory(),$policy,$policies,$contexts,$engine);
    $oldRollout=getenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
    putenv('COS_FEDERATION_CANONICAL_ORGANIZATION='.$org);
    try{
        $request=$coordinator->requestApproval($actor,$plan,'plan-'.$token);
        $planAction=$request['action_id'];
        $check($request['status']==='PENDING_APPROVAL','Plan approval request not pending.');
        $planDecision=(string)$wfDb->fetchOne('SELECT id FROM cos_approvals WHERE organization_id=? AND action_id=?',[$org,$planAction]);
        $check($planDecision!=='','Independent Plan approval missing.');
        $deny(fn()=>$approvalService->approve($org,$planDecision,$initiator));
        $deny(fn()=>$bridge->start($actor,$run,$plan,$planAction));
        $approvalService->approve($org,$planDecision,$approver);
        $check($actions->execute($org,$planAction,'order-plan-worker')?->status===\Kernel\Action\ActionStatus::Completed,'Plan approval worker failed.');
        $check($bridge->start($actor,$run,$plan,$planAction)['execution_authority']==='platform_workflow','Canonical Plan did not start.');
        $deny(fn()=>$bridge->start($actor,'duplicate-'.$token,$plan,$planAction));
        $check($bridge->advance($actor,$run,$planAction)['state']==='running','Canonical Run did not start running.');
        $results=[];
        foreach($steps as $step){
        $pending=$bridge->advance($actor,$run,$planAction);
        $check($pending['state']==='awaiting_human_approval','Canonical Action approval missing.');
        $actionId=$pending['action_id'];
        $approvalId=(string)$wfDb->fetchOne('SELECT id FROM cos_approvals WHERE organization_id=? AND action_id=?',[$org,$actionId]);
        $check($approvalId!=='','Workflow Action approval missing.');
        $deny(fn()=>$approvalService->approve($org,$approvalId,$initiator));
        $check($actions->executeOutcome($org,$actionId,'unapproved-worker')===null,'Unapproved Workflow Action executed.');
        $approvalService->approve($org,$approvalId,$approver);
        if(isset($config['after_approval']))($config['after_approval'])();
        $out=$actions->executeOutcome($org,$actionId,'order-workflow-worker');
        if(($config['expect_denied']??false)===true){
            $check($out!==null&&!$out->result->successful&&$out->action()->status===\Kernel\Action\ActionStatus::Failed,'Rejected Workflow Action unexpectedly executed.');
            $check($actions->executeOutcome($org,$actionId,'denied-replay-worker')===null,'Failed Workflow Action executed again.');
            for($retry=0;$retry<2;$retry++){
                $blocked=$bridge->advance($actor,$run,$planAction);
                $check($blocked['state']==='manual_reconciliation_required'&&($blocked['action_id']??null)===$actionId,'Failed Workflow lost its original Action or attempted automatic replay.');
            }
            $check((int)$wfDb->fetchOne('SELECT COUNT(*) FROM cos_action_attempts WHERE organization_id=? AND action_id=?',[$org,$actionId])===1,'Rejected Workflow created another worker attempt.');
            $checkpoint=$wfDb->fetchAssociative('SELECT state,result_reference FROM cos_workflow_steps WHERE organization_id=? AND run_id=? AND step_id=?',[$org,$run,$step['id']]);
            $check(is_array($checkpoint)&&$checkpoint['state']==='claimed'&&$checkpoint['result_reference']===null,'Rejected Action produced a successful Workflow checkpoint.');
            return [];
        }
        $check($out!==null&&$out->result->successful&&$out->action()->status===\Kernel\Action\ActionStatus::Completed,'Approved Workflow Action failed: '.$step['capability_id']);
        $check($actions->executeOutcome($org,$actionId,'replay-worker')===null,'Completed Action replayed.');
        $check($bridge->advance($actor,$run,$planAction)['state']==='step_completed','Native Action receipt was not checkpointed.');
        $results[$step['id']]=$out->result->data;
        }
        $check($bridge->advance($actor,$run,$planAction)['state']==='completed','Canonical Workflow did not complete.');
        $eval=$goals->recordEvaluation($actor,'eval-'.$token,$run);
        $check($eval['result']==='satisfied'&&count($eval['criteria'])===count($criteria),'Native Goal outcome was not verified.');
        foreach($specCriteria as $i=>$expected)
            $check(($eval['criteria'][$i]['observed']??null)===$expected['expected'],'Native Goal evidence count differs.');
        foreach($steps as $step)if(in_array($step['capability_id'],\Domains\Sales\Automation\Action\SalesGovernedBusinessOperations::types(),true))
            $verifiedR1[$step['capability_id']]=true;
        return count($steps)===1?$results['payment']:$results;
    }finally{
        if($oldRollout===false)putenv('COS_FEDERATION_CANONICAL_ORGANIZATION');else putenv('COS_FEDERATION_CANONICAL_ORGANIZATION='.$oldRollout);
    }
};
$wfPayload=[...$payload,'amount'=>'60.00','source_reference'=>'workflow-capture'];
$wfRegistered=$executeWorkflow('finance.order.payment.register',$wfPayload,$requester,$reviewer);
$check($realStore->financialEvidence($org,$id)['net_collected_amount']==='40.00','Workflow registration incorrectly changed confirmed collection.');
$executeWorkflow('finance.order.payment.reconcile',[...$wfPayload,'operation_id'=>$wfRegistered['finance_reference'],
    'statement_reference'=>'workflow-statement-line','expected_revision'=>1],$reviewer,$requester);
$check($realStore->financialEvidence($org,$id)['net_collected_amount']==='100.00','Financial Workflow reconciliation did not settle captured evidence.');
echo "Finance actual Action + canonical financial Workflow claim, worker, receipt and checkpoint PASS (real independent Plan Approval and native Goal evaluation; no production cutover).\n";

// O5 source-side acceptance: durable, tenant-scoped lifecycle pagination and independent Finance.
$placementId=$sales->placement($org,$id)['event_id'];
$check($sales->lifecycleEvidence($org,$id)===['order_revision'=>1,'events'=>[],'next_after_revision'=>1,'has_more'=>false],
    'Placement must not be emitted again as a lifecycle event.');
$check($sales->lifecycleEvidence('foreign-'.$org,$id)===null,'Foreign tenant lifecycle read exposed evidence.');
$check($sales->lifecycleEvidence(strtoupper($org),$id)===null,'Case-variant tenant lifecycle read exposed evidence.');
$deny(fn()=>$sales->lifecycleEvidence($org,$id,2));
$deny(fn()=>$sales->lifecycleEvidence($org,$id,1,101));
$confirm=['schema_version'=>'1.0.0','event_id'=>bin2hex(random_bytes(16)),'event_type'=>'sales.commerce.order.confirm',
    'organization_id'=>$org,'order_id'=>$id,'revision'=>2,'occurred_at'=>'2026-11-25T00:01:00.000000Z',
    'previous_status'=>'placed','status'=>'confirmed','actor_id'=>(int)$requester,'reference'=>'private-confirm-fixture',
    'effects'=>[],'financial_evidence_reference'=>null,'realized_revenue'=>null,'correlation_id'=>'fixture'];
$cancel=[...$confirm,'event_id'=>bin2hex(random_bytes(16)),'event_type'=>'sales.commerce.order.cancel','revision'=>3,
    'occurred_at'=>'2026-11-25T00:02:00.000000Z','previous_status'=>'confirmed','status'=>'cancelled','reference'=>'private-cancel-fixture'];
$db->beginTransaction();
$sales->transition($org,$id,1,'confirmed',$confirm);
$db->rollBack();
$check($sales->lifecycleEvidence($org,$id)['events']===[],'Rolled-back lifecycle fact escaped.');
$db->beginTransaction();
$sales->transition($org,$id,1,'confirmed',$confirm);
$sales->transition($org,$id,2,'cancelled',$cancel);
$db->commit();
$page=$sales->lifecycleEvidence($org,$id,1,1);
$check($page['order_revision']===3&&$page['has_more']&&$page['next_after_revision']===2&&
    count($page['events'])===1&&$page['events'][0]['event_id']===$confirm['event_id'],'First lifecycle page invalid.');
$check($page===$sales->lifecycleEvidence($org,$id,1,1),'Lifecycle replay changed immutable evidence.');
$next=$sales->lifecycleEvidence($org,$id,2,1);
$check(!$next['has_more']&&$next['next_after_revision']===3&&$next['events'][0]['event_id']===$cancel['event_id']&&
    $next['events'][0]['status']==='cancelled','Cancellation event missing.');
$check(!isset($next['events'][0]['actor_id'],$next['events'][0]['reference'])&&
    !array_key_exists('actor_id',$next['events'][0])&&!array_key_exists('reference',$next['events'][0])&&
    !array_key_exists('realized_revenue',$next['events'][0]),'Lifecycle evidence leaked private or financial fields.');
$check($sales->lifecycleEvidence($org,$id,3)['events']===[],'Exhausted lifecycle cursor repeated events.');
$check($sales->placement($org,$id)['event_id']===$placementId,'Lifecycle changed placement identity.');
$check($realStore->financialEvidence($org,$id)['net_collected_amount']==='100.00','Cancellation incorrectly changed Finance collection.');
echo "Sales O5 lifecycle evidence pagination, rollback, tenant isolation and Finance independence PASS.\n";

// O6: independently approved canonical Workflow Actions invoke the same native Sales and Product services.
$orderAccess=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAccessControl($db);
$orderScope=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesWorkspaceScope($db,$orderAccess);
$entityScope=new \Domains\Sales\Application\Service\SalesEntityScopeService($orderScope,new \Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesWorkspaceReadModel($db));
$orderAuthority=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesMutationAuthority($db,$orderScope,$orderAccess);
$orderGuard=new \Domains\Sales\Application\Service\SalesMutationGuard($tx,$orderAuthority);
$orderApproval=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesApprovalAuthority($db);
$orderReceipts=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesMutationReceiptRepository($db);
$productStore=new \Domains\ProductManagement\Infrastructure\Persistence\MySqlProductManagementStore($db,new \Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore($db));
$products=new \Domains\ProductManagement\Application\Service\ProductManagementService($productStore);
$productReferences=new \Domains\ProductManagement\Application\Service\ProductCommercialReference($productStore);
$references=new class($productReferences) implements \Domains\Sales\Application\Contract\SalesOfferReferencePort{
    public function __construct(private \Domains\ProductManagement\Application\Contract\ProductCommercialReferencePort $products){}
    public function resolve(string $org,array $line):?array{
        return $this->products->quoteReference($org,(string)($line['product_id']??''),(string)($line['variant_id']??''));
    }
};
$nativeOrders=new \Domains\Sales\Application\Service\SalesOrderService($sales,$orderReceipts,$orderGuard,$entityScope,$references,$realStore);
$nativeLifecycle=new \Domains\Sales\Application\Service\SalesOrderLifecycleService($sales,$sales,$orderReceipts,$orderGuard,$orderAuthority,$entityScope,$productStore,$active,
    new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOrderSourceRepository($db),$realStore);
$governed=new \Domains\Sales\Automation\Action\SalesGovernedOrderHandler($nativeOrders,$nativeLifecycle,$orderGuard,$orderApproval,$active,$capabilities);
$salesModule=new class($governed) implements \Kernel\Module\DomainModuleInterface,\Kernel\Module\Contract\ActionOwningModuleInterface{
    public function __construct(private \Domains\Sales\Automation\Action\SalesGovernedOrderHandler $handler){}
    public function name():string{return 'sales';}
    public function actionTypes():array{return $this->handler::types();}
    public function actionHandlers():array{return [$this->handler];}
};
$salesRegistry=new \Kernel\Module\DomainModuleRegistry([$salesModule]);
$grant=$db->prepare('INSERT INTO sales_user_capabilities(organization_id,user_id,capability,status,granted_by,created_at,updated_at) VALUES (?,?,?,"ACTIVE",?,NOW(6),NOW(6))');
$grant->execute([$org,(int)$requester,'sales.workspace.use',$requester]);
$grant->execute([$org,(int)$reviewer,'sales.approval.any_team',$requester]);
foreach($salesModule->actionTypes() as $type){
    $db->prepare('INSERT INTO cos_policies (id,organization_id,code,name,action_type,conditions,decision,priority,version,status) VALUES (?,?,?,?,?,?,"APPROVAL_REQUIRED",1,1,"ACTIVE")')->execute([bin2hex(random_bytes(16)),$org,$type,$type,$type,'[]']);
}
$nonce=bin2hex(random_bytes(6));
$product=$products->createProduct($org,['name'=>'Governed order stock','sku'=>'GOV-'.$nonce,'type'=>'stock'],'gov-product-'.$nonce,$requester,'gov-order');
$db->prepare("UPDATE pm_products SET status='active' WHERE organization_id=? AND product_id=?")->execute([$org,$product['product_id']]);
$location=$products->createLocation($org,'GOV-'.$nonce,'Governed order warehouse','gov-location-'.$nonce,$requester,'gov-order')['location_id'];
$products->recordMovement($org,$product['variant_id'],$location,'10','receipt','manual:gov-opening','gov-opening-'.$nonce,$requester,'gov-order');
$config=['owner'=>'sales','effect'=>'internal','domains'=>$salesRegistry,'handlers'=>[$governed],'approval'=>$orderApproval];
$prefix=\Domains\Sales\Automation\Action\SalesGovernedOrderHandler::PREFIX;
// Unified O7: native Marketing campaign, then governed Order, stock, Finance collection and correction.
$mktActor=new \Kernel\Tenant\Model\TenantContext(\Kernel\Shared\Domain\UserId::fromString($requester),
    \Kernel\Shared\Domain\OrganizationId::fromString($org),\Kernel\Identity\Model\OrganizationRole::fromString('manager'),
    [\Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::ACCESS),
     \Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::MANAGE)]);
$mktCampaigns=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingCampaignRepository($db);
$mktPolicy=new \Domains\Marketing\Domain\MarketingDraftPolicy();
$mktCampaign=$mktCampaigns->createDraft($org,$requester,$mktPolicy->validate(['name'=>'Unified O7 campaign','business_objective'=>'Order collection evidence']),'o7-campaign-'.$nonce)['id'];
$mktService=new \Domains\Marketing\Application\Service\MarketingCampaignService($mktCampaigns,$mktPolicy);
$mktPlanning=new \Domains\Marketing\Application\Service\MarketingPlanningService(new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPlanningRepository($db),new \Domains\Marketing\Domain\MarketingPlanningPolicy());
$mktAccounts=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidReadRepository($db);
$mktFunding=new \Domains\Finance\Application\Service\FinanceBudgetControlService(new \Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinanceBudgetRepository($db),new \Domains\Finance\Domain\BudgetControlPolicy());
$mktPlans=new \Domains\Marketing\Application\Service\MarketingPaidLaunchPlanService(
    new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidLaunchPlanStore($db),$mktService,$mktPlanning,$mktAccounts,
    new \App\Infrastructure\Marketing\FinanceFundingReadAdapter($mktFunding,$active),$active);
$mktCommerce=new \Domains\Marketing\Application\Service\MarketingCommerceAttributionService($mktService,$mktPlans,
    new \App\Infrastructure\Marketing\CommerceOrderReadAdapter($active,$sales,$sales,$sales),
    new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingCommerceAttributionStore($db),
    new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingAttributionStore($db),$active,
    new \App\Infrastructure\Marketing\CommerceFinancialReadAdapter($active,$realStore));
$created=$executeWorkflow($prefix.'create',['currency'=>'EUR','lines'=>[['sku'=>$product['sku'],'title'=>'Governed stock',
    'product_id'=>$product['product_id'],'variant_id'=>$product['variant_id'],'quantity'=>5,'unit_price'=>'10.00']],'total'=>'50.00','attribution'=>['utm_campaign'=>$mktCampaign,'utm_source'=>'google','utm_medium'=>'organic']],
    $requester,$reviewer,[...$config,'target_type'=>'sales_order_intent','target'=>bin2hex(random_bytes(16))]);
$governedId=$created['order']['order_id'];
$mktDay=substr($sales->placement($org,$governedId)['occurred_at'],0,10);
$mktReceipt=$mktCommerce->capture($mktActor,$mktCampaign,null,[$governedId],'o7-capture-'.$nonce);
$check($mktReceipt['results'][0]['state']==='RECORDED'&&$mktCommerce->capture($mktActor,$mktCampaign,null,[$governedId],'o7-capture-'.$nonce)===$mktReceipt,'Unified Marketing placement or replay failed.');
$mktReport=static fn()=>$mktCommerce->report($mktActor,$mktCampaign,null,$mktDay,$mktDay);
$r=$mktReport();
$check($r['placed_orders']===1&&$r['placed_order_value']===[['currency'=>'EUR','minor'=>5000]]&&
    $r['net_cash_received']===null&&$r['lifecycle']['current_status_counts']['placed']===1,'Initial native attribution misrepresented collection or status.');
$check($sales->placement($org,$governedId)['order_total']==='50.00','Governed creation has no native placement.');
$runOrder=static fn(string $op,int $rev,string $reference,array $extra=[],array $override=[])=>$executeWorkflow($prefix.'lifecycle.'.$op,
    ['order_id'=>$governedId,'expected_revision'=>$rev,'reference'=>$reference,...$extra],$requester,$reviewer,[...$config,'target'=>$governedId,...$override]);
$check($runOrder('confirm',1,'gov-confirm')['status']==='confirmed','Governed confirmation failed.');
$alloc=$runOrder('allocate',2,'gov-reserve',['items'=>[['line_no'=>1,'location_id'=>$location,'quantity'=>5]]]);
$allocation=$alloc['allocations'][0]['allocation_id'];
$check($productStore->stockBalance($org,$product['variant_id'],$location)['reserved']==='5.000000','Governed reservation not owned by Product.');
$runOrder('release',3,'gov-release',['items'=>[['allocation_id'=>$allocation,'quantity'=>1]]]);
$part=$runOrder('fulfill',4,'gov-dispatch',['items'=>[['allocation_id'=>$allocation,'quantity'=>3]]]);
$check($part['fulfilled_quantity']===3,'Governed partial fulfillment missing.');
$cancel=$runOrder('cancel',5,'gov-cancel');
$check($cancel['status']==='cancelled'&&$cancel['cancelled_quantity']===2,'Governed cancel failed.');
$return=$runOrder('return',6,'gov-return',['items'=>[['allocation_id'=>$allocation,'quantity'=>1,'disposition'=>'restock']]]);
$check($return['returned_quantity']===1&&$productStore->stockBalance($org,$product['variant_id'],$location)['on_hand']==='8.000000'&&
    $productStore->stockBalance($org,$product['variant_id'],$location)['reserved']==='0.000000','Governed return or stock balance failed.');
$runOrder('return',7,'gov-revoked',['items'=>[['allocation_id'=>$allocation,'quantity'=>1,'disposition'=>'restock']]],[
    'expect_denied'=>true,'after_approval'=>static function()use($db,$org,$reviewer):void{
        $db->prepare('DELETE FROM sales_user_capabilities WHERE organization_id=? AND user_id=? AND capability="sales.approval.any_team"')->execute([$org,(int)$reviewer]);
    }]);
$check($sales->get($org,$governedId)['revision']===7&&$productStore->stockBalance($org,$product['variant_id'],$location)['on_hand']==='8.000000',
    'Revoked approver produced a native order or stock effect.');
$check($realStore->financialEvidence($org,$governedId)===null,'Governed lifecycle fabricated Finance evidence.');
$check(count($sales->lifecycleEvidence($org,$governedId)['events'])===6,'Governed lifecycle journal incomplete.');
echo "Sales O6 governed native create/confirm/allocate/release/fulfill/cancel/return + independent Approval, revoked approver and Product readback PASS (real independent Plan Approval and native Goal evaluation).\n";

$r=$mktReport();
$check($r['placed_orders']===1&&$r['lifecycle']['coverage']==='COMPLETE_CAPTURED_COHORT'&&
    $r['lifecycle']['current_status_counts']['cancelled']===1&&$r['lifecycle']['orders_with_returns']===1&&$r['net_cash_received']===null,
    'Unified Marketing did not consume actual Sales/Product lifecycle corrections.');
$finConfig=['target'=>$governedId];
$settle=static function(array $p,int $rev)use($executeWorkflow,$requester,$reviewer,$finConfig):array{
    $registered=$executeWorkflow('finance.order.payment.register',$p,$requester,$reviewer,$finConfig);
    $executeWorkflow('finance.order.payment.reconcile',[...array_diff_key($p,['parent_id'=>true]),'operation_id'=>$registered['finance_reference'],
        'statement_reference'=>'o7-statement-'.$rev,'expected_revision'=>$rev],$reviewer,$requester,$finConfig);
    return $registered;
};
$pay=['order_id'=>$governedId,'kind'=>'capture','amount'=>'40.00','currency'=>'EUR','source_account'=>'test-bank','source_reference'=>'o7-capture-'.$nonce];
$registered=$settle($pay,0);
$check($mktReport()['net_cash_received']===[['currency'=>'EUR','minor'=>4000]],'Native Finance capture did not reach Marketing.');
$settle([...$pay,'kind'=>'refund','amount'=>'10.00','source_reference'=>'o7-refund-'.$nonce,'parent_id'=>$registered['finance_reference']],1);
$settle([...$pay,'kind'=>'chargeback','amount'=>'5.00','source_reference'=>'o7-chargeback-'.$nonce,'parent_id'=>$registered['finance_reference']],2);
$r=$mktReport();
$check($r['placed_orders']===1&&$r['placed_order_value']===[['currency'=>'EUR','minor'=>5000]]&&
    $r['net_cash_received']===[['currency'=>'EUR','minor'=>2500]]&&$r['financial_evidence_status']==='COMPLETE_CAPTURED_COHORT'&&
    $r['confirmed_financial_totals']===[['currency'=>'EUR','captured_minor'=>4000,'refunded_minor'=>1000,'chargeback_minor'=>500,'net_collected_minor'=>2500]]&&
    $r['recognized_revenue']===null&&$r['roas']===null,'Unified financial corrections were not reflected independently of placement.');
$check($nativeOrders->read($org,(int)$requester,$governedId)['financial_evidence']['net_collected_amount']==='25.00','Sales and Marketing Finance readbacks diverged.');
$check($productStore->stockBalance($org,$product['variant_id'],$location)['on_hand']==='8.000000','Finance or Marketing modified Product inventory.');
$check($sales->placement('foreign-'.$org,$governedId)===null&&$realStore->financialEvidence('foreign-'.$org,$governedId)===null,'Foreign tenant source leaked.');
echo "O7 unified native Marketing campaign -> governed Sales order -> Product allocation/partial fulfillment/cancel/return -> independently approved Finance capture/refund/chargeback -> Marketing native report PASS (real independent Plan Approval and native Goal evaluation, not provider settlement).\n";

// I4 mixed-domain Goal with immutable known inputs: one independently approved Plan,
// two independently approved Actions, separate Sales and Finance outcome criteria.
$grant->execute([$org,(int)$reviewer,'sales.approval.any_team',$requester]);
$mixedOrder=$executeWorkflow($prefix.'create',['currency'=>'EUR','lines'=>[['sku'=>'MIXED','title'=>'Mixed Goal fixture',
    'quantity'=>1,'unit_price'=>'10.00']],'total'=>'10.00'],$requester,$reviewer,
    [...$config,'target_type'=>'sales_order_intent','target'=>bin2hex(random_bytes(16))])['order']['order_id'];
$mixedPay=['order_id'=>$mixedOrder,'kind'=>'capture','amount'=>'10.00','currency'=>'EUR',
    'source_account'=>'test-bank','source_reference'=>'mixed-goal-'.$nonce];
$mixedRegistry=new \Kernel\Module\DomainModuleRegistry([$salesModule,$domain]);
$mixed=$executeWorkflow($prefix.'lifecycle.confirm',['order_id'=>$mixedOrder,'expected_revision'=>1,'reference'=>'mixed-confirm'],
    $requester,$reviewer,[...$config,'target'=>$mixedOrder,'domains'=>$mixedRegistry,'handlers'=>[$governed,$handler],
    'extra_steps'=>[['id'=>'register-payment','depends_on'=>['payment'],'capability_id'=>'finance.order.payment.register','capability_version'=>'1.0.0',
        'input'=>['target_type'=>'sales_order','target_id'=>$mixedOrder,'parameters'=>$mixedPay]]]]);
$check($mixed['payment']['status']==='confirmed'&&$mixed['register-payment']['state']==='pending_registration'&&
    $sales->get($org,$mixedOrder)['revision']===2&&$realStore->financialEvidence($org,$mixedOrder)===null,
    'Mixed-domain Goal did not preserve independent Sales state and unconfirmed Finance registration.');
echo "I4 mixed Sales and Finance canonical Goal, independent Plan and Actions, native outcomes PASS\n";

// O7 negative acceptance on a real approved Goal: stale commercial revision and
// revoked current financial Policy must not fabricate receipts or permit replay.
$mixedBefore=$sales->get($org,$mixedOrder);
$mixedJournal=$sales->lifecycleEvidence($org,$mixedOrder);
$executeWorkflow($prefix.'lifecycle.cancel',['order_id'=>$mixedOrder,'expected_revision'=>1,'reference'=>'o7-stale-cancel'],
    $requester,$reviewer,[...$config,'target'=>$mixedOrder,'expect_denied'=>true]);
$check($sales->get($org,$mixedOrder)===$mixedBefore&&$sales->lifecycleEvidence($org,$mixedOrder)===$mixedJournal,
    'Stale approved Sales command changed order revision or evidence.');
$opsBefore=$realStore->operations($org,$mixedOrder,0,100);
$paymentPolicyId=(string)$wfDb->fetchOne('SELECT id FROM cos_policies WHERE organization_id=? AND action_type=? AND status="ACTIVE"',
    [$org,'finance.order.payment.register']);
$check($paymentPolicyId!=='','Explicit Finance register Policy missing.');
try{
    $executeWorkflow('finance.order.payment.register',[...$mixedPay,'source_reference'=>'o7-policy-revoked-'.$nonce],
        $requester,$reviewer,['target'=>$mixedOrder,'expect_denied'=>true,'after_approval'=>static function()use($wfDb,$org,$paymentPolicyId):void{
            $wfDb->executeStatement('UPDATE cos_policies SET decision="DENIED" WHERE organization_id=? AND id=?',[$org,$paymentPolicyId]);
        }]);
}finally{
    $wfDb->executeStatement('UPDATE cos_policies SET decision="APPROVAL_REQUIRED" WHERE organization_id=? AND id=?',[$org,$paymentPolicyId]);
}
$check($realStore->operations($org,$mixedOrder,0,100)===$opsBefore&&$realStore->financialEvidence($org,$mixedOrder)===null,
    'Revoked financial Policy created a registration or confirmed money.');
$check($sales->get($org,$mixedOrder)===$mixedBefore&&$sales->lifecycleEvidence($org,$mixedOrder)===$mixedJournal,
    'Rejected Finance Action modified Sales.');
echo "O7 approved stale Sales revision and revoked Finance Policy, manual reconciliation and no automatic replay PASS\n";

// R1: real governed commands, not a substitute Action handler or synthetic outcome.
// Independent native provider resolves lead event/receipt and team record from the completed canonical Run.
$businessRead=new \Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesWorkspaceReadModel($db);
$businessOffers=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOfferRepository($db);
$businessTeams=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesTeamAdministration($db);
$businessFactory=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesWriteServiceFactory($db,
    new \Domains\Property\Infrastructure\ReadModel\MySql\MysqlPropertyReferencePort($db),
    new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAssignmentAuthority($db));
$leadCreate=new \App\Application\Sales\Command\CreateSalesLeadCommandHandler($businessFactory);
$leadUpdate=new \App\Application\Sales\Command\UpdateSalesLeadCommandHandler($businessFactory);
$teamManage=new \App\Application\Sales\Command\ManageSalesTeamCommandHandler($businessTeams,
    new \App\Application\Sales\Admin\SalesAdminAuthorization($orderAccess),$orderReceipts,$tx);
$identities=new \App\Infrastructure\Persistence\MySql\MysqlIdentityRepository($db);
$tenantFactory=new \Kernel\Tenant\Service\TenantContextFactory(new \App\Security\RoleTenantPermissionResolver());
$businessPlanEvidence=new \App\Persistence\Workflow\FederationWorkflowPlanApprovalEvidence($wfDb,new \App\Persistence\Federation\FederationPlanApprovalEvidenceReader($wfDb));
$businessReceipt=new \App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier($wfDb,$businessPlanEvidence);
$businessAdmission=new \App\Persistence\Workflow\FailClosedWorkflowActionWorkerAdmission(
    new \App\Persistence\Workflow\DoctrineWorkflowActionWorkerAdmission($wfDb,$businessPlanEvidence,$capabilities,$active,
        new \App\Persistence\Workflow\WorkflowPredecessorReceiptVerifier($businessReceipt,new \App\Persistence\Workflow\FailClosedWorkflowNonActionReceiptEvidence())),
    $org);

$businessEvents=new \Kernel\Event\EventBus(new \Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore($db),$tx);
$businessDeals=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlDealRepository($db);
$businessPipelines=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlPipelineRepository($db);
$businessStages=new \Domains\Sales\Application\UseCase\ChangeDealStage($businessDeals,$businessPipelines,new \Domains\Sales\Domain\Policy\StageTransitionPolicy(),$businessEvents,$tx,
    new \App\Infrastructure\Automation\CanonicalSalesStageApprovalVerifier($businessAdmission,$orderApproval,$orderAuthority));
$businessCommercial=new \Domains\Sales\Application\UseCase\SetDealCommercialTerms($businessDeals,$businessDeals,$businessEvents,$tx);
$businessDealHandler=new \App\Application\Sales\Command\ManageSalesDealCommandHandler($businessDeals,
    new \Domains\Sales\Application\UseCase\AssignDealOwner($businessDeals,$businessEvents,$tx,new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAssignmentAuthority($db)),
    $businessStages,$businessCommercial,$orderReceipts,$tx);
$businessScheduled=new \Domains\Sales\Application\Service\SalesScheduledActivityService(
    new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesScheduledActivityRepository($db),
    new \Domains\Sales\Application\UseCase\ScheduleLeadFollowup(new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlLeadFollowupRepository($db),$businessEvents,$tx),
    $orderReceipts,$businessEvents,$tx);
$businessOfferService=new \Domains\Sales\Application\Service\SalesOfferService($businessOffers,
    new \App\Application\Sales\SalesOfferReferenceResolver($productReferences,new \Domains\Property\Infrastructure\ReadModel\MySql\MysqlPropertyReferencePort($db),$active),
    $businessDeals,$businessCommercial,$orderReceipts,$businessEvents,$tx);
$activityOps=new \Domains\Sales\Application\Service\SalesOperationService(
    new class implements \Domains\Sales\Application\Contract\MessageGatewayInterface{
        public function send(\Domains\Sales\Application\DTO\SendMessageCommand $command):\Domains\Sales\Application\DTO\OperationResult{throw new RuntimeException('Unexpected external delivery.');}
    },new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOperationRepository($db),$businessEvents,$tx);
$businessActivity=new \App\Application\Sales\Command\ManageSalesActivityCommandHandler($businessFactory,$activityOps,$businessDeals,
    new \Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesActivityEvidenceReadModel($db),$orderReceipts,
    new \Domains\Property\Infrastructure\ReadModel\MySql\MysqlPropertyReferencePort($db),$tx);
$crmRepo=new \Infrastructure\Integration\Crm\MysqlCrmInboxRepository($db);
$crmEventsModule=new class implements \Kernel\Module\DomainModuleInterface,\Kernel\Module\Contract\EventOwningModuleInterface{
    public function name():string{return 'sales';}
    public function eventTypes():array{return [\Domains\Sales\Automation\Event\LeadChanged::TYPE,\Domains\Sales\Automation\Event\ClientCaseChanged::TYPE];}
    public function ruleContextProvider():\Kernel\Rule\Contract\RuleContextProviderInterface{
        return new class implements \Kernel\Rule\Contract\RuleContextProviderInterface{
            public function contextFor(\Kernel\Event\DomainEvent $event):array{return [];}
        };
    }
};
$crmProcessor=new \Domains\Sales\Application\UseCase\ProcessCrmInbox($crmRepo,
    new \Infrastructure\Integration\Crm\MysqlCrmInboundApplier($db),new \Kernel\Module\DomainModuleRegistry([$crmEventsModule]),$businessEvents,$tx);
$businessBus=new \App\Infrastructure\Messenger\SymfonyCommandBus(new \Symfony\Component\Messenger\MessageBus([
    new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware(new \Symfony\Component\Messenger\Handler\HandlersLocator([
        \App\Application\Integration\Command\ProcessCrmInboxNowCommand::class=>[new \App\Application\Integration\Command\ProcessCrmInboxNowCommandHandler($crmRepo,$crmProcessor,$orderGuard,$orderReceipts)],
        \App\Application\Integration\Command\RecoverCrmInboxCommand::class=>[new \App\Application\Integration\Command\RecoverCrmInboxCommandHandler($crmRepo,$orderReceipts,$tx,new \App\Application\Integration\IntegrationMutationAudit(new \Infrastructure\Platform\Persistence\MySql\Audit\MysqlAuditRepository($db)))],
        \App\Application\Sales\Command\ReceivePublicLeadCommand::class=>[new \App\Application\Sales\Command\ReceivePublicLeadCommandHandler($businessFactory)],
        \App\Application\Sales\Command\ManageSalesLeadStateCommand::class=>[new \App\Application\Sales\Command\ManageSalesLeadStateCommandHandler($businessFactory,$businessRead,$orderReceipts,$tx)],
        \App\Application\Sales\Command\ManageSalesActivityCommand::class=>[$businessActivity],
        \App\Application\Sales\Command\CreateSalesLeadCommand::class=>[$leadCreate],
        \App\Application\Sales\Command\UpdateSalesLeadCommand::class=>[$leadUpdate],
        \App\Application\Sales\Command\ManageSalesTeamCommand::class=>[$teamManage],
        \App\Application\Sales\Command\ConvertSalesLeadToOpportunityCommand::class=>[new \App\Application\Sales\Command\ConvertSalesLeadToOpportunityCommandHandler($businessFactory)],
        \App\Application\Sales\Command\ManageSalesDealCommand::class=>[static function($command)use($businessDealHandler){
            try{return $businessDealHandler($command);}catch(Throwable $e){fwrite(STDERR,'Synthetic R2 stage fixture: '.get_class($e).': '.$e->getMessage()."\n");throw $e;}
        }],
        \App\Application\Sales\Command\ManageScheduledSalesActivityCommand::class=>[new \App\Application\Sales\Command\ManageScheduledSalesActivityCommandHandler($businessScheduled)],
        \App\Application\Sales\Command\ManageSalesOfferCommand::class=>[new \App\Application\Sales\Command\ManageSalesOfferCommandHandler($businessOfferService)],
    ])),
]));
// Use a bounded provider-port fixture, with real native reconciliation and JobQueue.
$connectionId=bin2hex(random_bytes(16));$externalId='gid://shopify/Order/987654321';
$commerceSource=new class($org,$connectionId,$externalId,$product) implements \Domains\ProductManagement\Application\Contract\CommerceOrderSourcePort,\Domains\ProductManagement\Application\Contract\CommerceOrderFulfillmentEvidencePort{
    public bool $fulfilled=false;public bool $wrongLine=false;
    public function __construct(private string $org,private string $connection,private string $external,private array $product){}
    private function assertSource(string $org,string $channel,string $connection):void{
        if($org!==$this->org||$channel!=='shopify'||$connection!==$this->connection)throw new DomainException('Unverified commerce fixture identity.');
    }
    public function order(string $org,string $channel,string $connection,string $externalId):array{
        $this->assertSource($org,$channel,$connection);
        if($externalId!==$this->external)throw new DomainException('Unknown provider order.');
        return ['channel'=>$channel,'store_reference'=>$connection,'external_order_id'=>$externalId,
            'created_at'=>'2026-11-25T00:00:00.000000Z','updated_at'=>$this->fulfilled?'2026-11-25T00:00:02.000000Z':'2026-11-25T00:00:01.000000Z',
            'provider_hash'=>hash('sha256',$externalId.($this->fulfilled?':fulfilled':'')),'financial_status'=>'PAID','fulfillment_status'=>$this->fulfilled?'FULFILLED':'UNFULFILLED',
            'line_identities'=>['gid://shopify/LineItem/123'=>1],
            'cancelled_at'=>null,'test'=>false,'edited'=>false,'tax_included'=>false,'currency'=>'EUR',
            'original_total'=>'10.00','current_total'=>'10.00','terms'=>['currency'=>'EUR','total'=>'10.00',
                'lines'=>[['sku'=>$this->product['sku'],'title'=>'Source fixture','product_id'=>$this->product['product_id'],
                    'variant_id'=>$this->product['variant_id'],'quantity'=>1,'unit_price'=>'10.00']]]];
    }
    public function fulfillmentEvidence(string $org,string $channel,string $connection,string $externalId,bool $includeLifecycle=false):array{
        $this->assertSource($org,$channel,$connection);
        if($externalId!==$this->external||!$includeLifecycle||!$this->fulfilled)throw new DomainException('Incomplete fixture lifecycle.');
        $line='gid://shopify/LineItem/'.($this->wrongLine?'124':'123');
        $page=static fn(array $nodes):array=>['nodes'=>$nodes,'pageInfo'=>['hasNextPage'=>false]];
        $provider=['order'=>['id'=>$externalId,'updatedAt'=>'2026-11-25T00:00:02Z','cancelledAt'=>null,'test'=>false,'edited'=>false,'displayFulfillmentStatus'=>'FULFILLED',
            'lineItems'=>$page([['id'=>$line,'quantity'=>1]]),
            'fulfillmentOrders'=>$page([]),'fulfillmentsCount'=>['count'=>1,'precision'=>'EXACT'],
            'fulfillments'=>[['id'=>'gid://shopify/Fulfillment/125','status'=>'SUCCESS','location'=>['id'=>'gid://shopify/Location/126'],
                'fulfillmentLineItems'=>$page([['id'=>'gid://shopify/FulfillmentLineItem/127','quantity'=>1,'lineItem'=>['id'=>$line]]])]],
            'returns'=>$page([['id'=>'gid://shopify/Return/128','status'=>'CLOSED','returnLineItems'=>$page([
                ['__typename'=>'ReturnLineItem','id'=>'gid://shopify/ReturnLineItem/129','quantity'=>1,'processedQuantity'=>1,'fulfillmentLineItem'=>['id'=>'gid://shopify/FulfillmentLineItem/127']]])]])]];
        $fact=\Domains\ProductManagement\Infrastructure\Shopify\ShopifyFulfillmentEvidenceNormalizer::normalize($provider,$externalId,true);
        return ['organization_id'=>$org,'channel'=>$channel,'connection_id'=>$connection,'configured_inventory_mode'=>'shopify_authoritative',
            ...$fact,'canonical_line_mappings'=>[$line=>['product_id'=>$this->product['product_id'],'variant_id'=>$this->product['variant_id']]],
            'unmapped_locations'=>[],'inventory_authority'=>'external_review','local_stock_write_allowed'=>false,'physical_commitment_verified'=>false];
    }
    public function page(string $org,string $channel,string $connection,?string $cursor,?string $cutoff=null):array{
        $this->assertSource($org,$channel,$connection);
        return ['order_ids'=>[$this->external],'next_cursor'=>null,'historical_access'=>'provider_limited'];
    }
    public function pendingPage(string $org,string $channel,string $connection,?string $cursor):array{
        $this->assertSource($org,$channel,$connection);
        return ['deliveries'=>[],'next_cursor'=>null];
    }
    public function pending(string $org,string $channel,string $connection):array{
        $this->assertSource($org,$channel,$connection);return [];
    }
    public function assertDelivery(string $org,string $channel,string $connection,string $delivery,string $externalId):void{
        throw new DomainException('No signed delivery in fixture.');
    }
    public function acknowledge(string $org,string $channel,string $connection,string $delivery,string $externalId):void{
        throw new DomainException('No signed delivery in fixture.');
    }
};
$businessReconcile=new \Domains\Sales\Application\Service\SalesOrderReconciliationService($commerceSource,
    new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOrderSourceRepository($db),$sales,$orderReceipts,$orderGuard,$nativeOrders,$references,$sales);
$businessSyncRepo=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOrderSyncRepository($db);
$businessSync=new \Domains\Sales\Application\Service\SalesOrderSyncService($businessSyncRepo,$commerceSource,$businessReconcile,$orderGuard,
    new \Infrastructure\Platform\Persistence\MySql\Queue\MysqlJobQueue($db,$tx,$businessEvents),
    new \Infrastructure\Platform\ReadModel\MySql\MysqlAsyncOperationReadModel($db),$active);
$businessHandler=new \App\Infrastructure\Automation\SalesGovernedBusinessHandler([
    new \App\Infrastructure\Capability\SalesOrderSourceReconcileAdapter($businessReconcile),
    new \App\Infrastructure\Capability\SalesOrderSyncStartAdapter($businessSync),
    new \App\Infrastructure\Capability\SalesCrmInboxProcessAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesCrmInboxRecoverAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesDealOwnerAssignAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesLeadIntakeAdapter($businessBus,$businessRead),
    new \App\Infrastructure\Capability\SalesLeadStateManageAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesActivityRecordAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesActivityCompleteAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesActivityRescheduleAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesActivityCancelAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesFollowupScheduleAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesPropertyMatchRecordAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesLeadCreateAdapter($businessBus,$businessRead),
    new \App\Infrastructure\Capability\SalesLeadQualificationAdapter($businessBus,$businessRead),
    new \App\Infrastructure\Capability\SalesTeamManageAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesClientCaseCreateAdapter($businessBus,$businessRead),
    new \App\Infrastructure\Capability\SalesPipelineStageManageAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesDealCommercialSetAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesDealOutcomeRecordAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesLeadFollowupScheduleAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesLeadActivityCompleteAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesLeadActivityRescheduleAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesLeadActivityCancelAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesOfferCreateAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesOfferReviseAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesOfferTransitionAdapter($businessBus),
    new \App\Infrastructure\Capability\SalesNegotiationRecordAdapter($businessBus),
],new \App\Application\Sales\SalesDirectInvocationPolicy($entityScope,$orderAccess,$businessOffers,$orderGuard),
    $orderApproval,$businessAdmission,$active,$capabilities,$identities,$tenantFactory,$tx);
$businessModule=new class($businessHandler) implements \Kernel\Module\DomainModuleInterface,\Kernel\Module\Contract\ActionOwningModuleInterface{
    public function __construct(private \App\Infrastructure\Automation\SalesGovernedBusinessHandler $handler){}
    public function name():string{return 'sales';}
    public function actionTypes():array{return \Domains\Sales\Automation\Action\SalesGovernedBusinessOperations::types();}
    public function actionHandlers():array{return [$this->handler];}
};
$businessRegistry=new \Kernel\Module\DomainModuleRegistry([$businessModule]);
foreach($businessModule->actionTypes() as $type)
    $db->prepare('INSERT INTO cos_policies (id,organization_id,code,name,action_type,conditions,decision,priority,version,status) VALUES (?,?,?,?,?,?,"APPROVAL_REQUIRED",1,1,"ACTIVE")')
        ->execute([bin2hex(random_bytes(16)),$org,$type,$type,$type,'[]']);
$grant->execute([$org,(int)$requester,'sales.admin.teams.manage',$requester]);
$grant->execute([$org,(int)$requester,'sales.admin.integrations.manage',$requester]);
$grant->execute([$org,(int)$requester,'sales.deal.assign',$requester]);
$businessConfig=['owner'=>'sales','effect'=>'internal','domains'=>$businessRegistry,'handlers'=>[$businessHandler],'approval'=>$orderApproval,
    'outcome_factory'=>static fn($receipt)=>new \App\Persistence\Sales\SalesBusinessRunOutcomeProvider($wfDb,$businessRead,$orderReceipts,$receipt,
        new \Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesActivityEvidenceReadModel($db),
        new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesScheduledActivityRepository($db),$businessOffers,$businessTeams,
        new \Infrastructure\Integration\Crm\MysqlCrmInboxRepository($db),
        new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOrderSyncRepository($db))];
$createdLead=$executeWorkflow('sales.governed.lead.create',['full_name'=>'R1 canonical lead','email'=>'r1-'.$nonce.'@example.test'],
    $requester,$reviewer,[...$businessConfig,'target_type'=>'sales_intent','target'=>bin2hex(random_bytes(16))]);
$leadId=$createdLead['lead_id'];
$check((int)$businessRead->lead($org,$leadId)['assigned_user_id']===(int)$requester,'R1 native lead owner mismatch.');
$qualified=$executeWorkflow('sales.governed.lead.qualify',['lead_id'=>$leadId,'expected_status'=>'new','target_status'=>'contacted'],
    $requester,$reviewer,[...$businessConfig,'target_type'=>'lead','target'=>(string)$leadId]);
$check($qualified['status']==='contacted'&&$businessRead->lead($org,$leadId)['status']==='contacted','R1 native lead qualification mismatch.');
$teamResult=$executeWorkflow('sales.governed.team.manage',['operation'=>'create','code'=>'R1_'.strtoupper($nonce),'name'=>'R1 canonical team','assignment_mode'=>'ROUND_ROBIN'],
    $requester,$reviewer,[...$businessConfig,'target_type'=>'sales_team_intent','target'=>bin2hex(random_bytes(16))]);
$check($businessTeams->team($org,$teamResult['team']['team_id'])['assignment_mode']==='ROUND_ROBIN','R1/R2 native team assignment configuration mismatch.');
// Exercise the approval-required native edge with actual Workflow and independent approvals.
$pid=bin2hex(random_bytes(16));$from=bin2hex(random_bytes(16));$to=bin2hex(random_bytes(16));$wonStage=bin2hex(random_bytes(16));$lostStage=bin2hex(random_bytes(16));
$db->prepare('INSERT INTO sales_pipelines(id,organization_id,code,name,is_default,initial_stage_id,status,configuration_version) VALUES (?,?,?,"R2 approval fixture",1,NULL,"ACTIVE",1)')->execute([$pid,$org,'r2-'.$nonce]);
$insertStage=$db->prepare('INSERT INTO sales_pipeline_stages(id,organization_id,pipeline_id,code,name,sort_order,is_terminal,is_won,is_lost,probability_default,status,configuration_version) VALUES (?,?,?,?,?,?,?,?,?,?,"ACTIVE",1)');
$insertStage->execute([$from,$org,$pid,'NEW','New',10,0,0,0,5]);
$insertStage->execute([$to,$org,$pid,'QUALIFIED','Qualified',20,0,0,0,50]);
$insertStage->execute([$wonStage,$org,$pid,'WON','Won',30,1,1,0,100]);
$insertStage->execute([$lostStage,$org,$pid,'LOST','Lost',40,1,0,1,0]);
$db->prepare('UPDATE sales_pipelines SET initial_stage_id=? WHERE organization_id=? AND id=?')->execute([$from,$org,$pid]);
foreach([[$from,$to],[$to,$wonStage]] as [$f,$t]){
    $db->prepare('INSERT INTO sales_pipeline_transitions(id,organization_id,pipeline_id,from_stage_id,to_stage_id,requires_approval,conditions) VALUES (?,?,?,?,?,1,JSON_ARRAY())')->execute([bin2hex(random_bytes(16)),$org,$pid,$f,$t]);
}
$runBusiness=static fn(string $type,array $p,string $kind,string $target,array $extra=[])=>$executeWorkflow('sales.governed.'.$type,$p,$requester,$reviewer,[...$businessConfig,'target_type'=>$kind,'target'=>$target,...$extra]);
$imported=$runBusiness('order.source.reconcile',['channel'=>'shopify','connection_id'=>$connectionId,'external_order_id'=>$externalId],
    'sales_order_connection',$connectionId);
$check($imported['outcome']==='imported'&&is_string($imported['order_id'])&&$sales->placement($org,$imported['order_id'])['order_total']==='10.00',
    'Governed provider observation did not produce native placement.');
$check($realStore->financialEvidence($org,$imported['order_id'])===null&&$productStore->stockBalance($org,$product['variant_id'],$location)['on_hand']==='8.000000',
    'Provider observation created false Finance or inventory effect.');
$commerceSource->fulfilled=true;
$r3params=['channel'=>'shopify','connection_id'=>$connectionId,'external_order_id'=>$externalId,'include_fulfillment_evidence'=>true,'include_lifecycle_evidence'=>true];
$commerceSource->wrongLine=true;
$runBusiness('order.source.reconcile',$r3params,'sales_order_connection',$connectionId,['expect_denied'=>true]);
$commerceSource->wrongLine=false;
$q=$db->prepare('SELECT COUNT(*) FROM sales_order_external_fulfillment_evidence WHERE organization_id=? AND order_id=?');$q->execute([$org,$imported['order_id']]);
$check((int)$q->fetchColumn()===0,'Rejected lifecycle left durable evidence.');
$beforeExternal=$sales->get($org,$imported['order_id']);
$r3=$runBusiness('order.source.reconcile',$r3params,'sales_order_connection',$connectionId);
$check($r3['order_id']===$imported['order_id'],'Governed external lifecycle changed canonical order identity.');
$e=(new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOrderSourceRepository($db))->latestForOrder($org,$imported['order_id'])['fulfillment_evidence'];
$check($e['current_order_revision']&&$e['provider_lifecycle']['provider_fulfilled_quantity']===1&&$e['provider_lifecycle']['return_processed_quantity']===1
    &&!$e['local_stock_write_allowed']&&$sales->get($org,$imported['order_id'])===$beforeExternal
    &&$productStore->stockBalance($org,$product['variant_id'],$location)['on_hand']==='8.000000'
    &&$realStore->financialEvidence($org,$imported['order_id'])===null,
    'Governed R3 observation created an unauthorized stock/finance/commercial effect.');
echo "Sales R3.5 canonical Goal, independent Approval, external lifecycle readback, mismatch rollback and Product/Finance independence PASS (fixture provider).\n";
$syncStarted=$runBusiness('order.source.sync.start',['channel'=>'shopify','connection_id'=>$connectionId,'mode'=>'backfill'],
    'sales_order_connection',$connectionId);
$syncStatus=$businessSync->status($org,(int)$requester,$syncStarted['run_id'],null);
$check($syncStatus['discovery_state']==='pending'&&$syncStatus['counts']['discovery_pending']===1&&count($syncStatus['tasks'])===1,
    'Governed sync admission did not durably enqueue its first discovery task.');
echo "Sales R1 canonical provider observation and durable sync admission PASS (fixture provider, no live Shopify claim).\n";
$intake=$runBusiness('lead.intake',['full_name'=>'R1 operator intake','email'=>'r1-intake-'.$nonce.'@example.test'],'sales_intent',bin2hex(random_bytes(16)));
$check((int)$businessRead->lead($org,$intake['lead_id'])['client_case_id']>0,'Governed intake lacks linked Deal.');
$stateLead=$runBusiness('lead.create',['full_name'=>'R1 state lead','email'=>'r1-state-'.$nonce.'@example.test'],'sales_intent',bin2hex(random_bytes(16)));
$runBusiness('lead.state.manage',['lead_id'=>$stateLead['lead_id'],'expected_status'=>'new','target_status'=>'spam'],'lead',(string)$stateLead['lead_id']);
$conversion=$runBusiness('client_case.create',['lead_id'=>$leadId],'lead',(string)$leadId);
$dealId=$conversion['case_id'];
$dealSnapshot=static fn()=>\Domains\Sales\Application\Support\DealStateSnapshot::fromNative($businessDeals->getForStageChange($org,(string)$dealId),$org,$dealId);
$stageInput=['deal_id'=>$dealId,'expected_revision'=>$dealSnapshot()['revision'],'target_stage_id'=>$to];
$directStage=new \App\Application\Sales\Command\ManageSalesDealCommand(\Kernel\Shared\Domain\OrganizationId::fromString($org),(int)$requester,'stage.manage',$stageInput,'r2-direct-deny-'.$nonce,'r2-direct');
$deny(fn()=>$businessDealHandler($directStage));
$check($dealSnapshot()['stage_id']===$from,'Unapproved stage edge mutated.');
$stageResult=$runBusiness('pipeline.stage.manage',$stageInput,'deal',(string)$dealId);
$check($stageResult['changed']&&$dealSnapshot()['stage_id']===$to,'Approved pipeline edge was not executed.');
// Durable signed CRM event: DEAD recovery then independently approved native processing.
$crmId=$tx->transactional(fn()=>$crmRepo->receive($org,'aida','r1-crm-'.$nonce,'deal.updated',
    ['entity_type'=>'deal','external_id'=>(string)$dealId,'changes'=>['priority'=>'high']],'r1-crm-'.$nonce));
$db->prepare('UPDATE cos_crm_inbox SET status="DEAD",attempts=10 WHERE organization_id=? AND id=?')->execute([$org,$crmId]);
$dead=$crmRepo->status($org,$crmId);
$recovered=$runBusiness('crm.inbox.recover',['inbox_id'=>$crmId,'revision'=>$dead['revision'],'reason'=>'mapping_repaired'],'sales_crm_inbox',$crmId);
$check($recovered['requeued']&&$crmRepo->status($org,$crmId)['status']==='RECEIVED','Governed CRM recovery did not requeue.');
$current=$crmRepo->status($org,$crmId);
$processed=$runBusiness('crm.inbox.process',['inbox_id'=>$crmId,'revision'=>$current['revision']],'sales_crm_inbox',$crmId);
$check($processed['status']==='COMPLETED'&&!$processed['replayed']&&$crmRepo->status($org,$crmId)['attempts']===1
    &&$businessRead->deal($org,$dealId)['priority']==='high','Canonical CRM apply or completion failed.');
$runBusiness('crm.inbox.process',['inbox_id'=>$crmId,'revision'=>$current['revision']],'sales_crm_inbox',$crmId,['expect_denied'=>true]);
$check($crmRepo->status($org,$crmId)['attempts']===1,'Second Goal falsely repeated completed CRM processing.');
echo "Sales R1 independent CRM recovery, native processing receipt and duplicate Goal fence PASS.\n";
$commercial=$runBusiness('deal.commercial.set',['deal_id'=>$dealId,'expected_revision'=>$dealSnapshot()['revision'],'deal_value'=>'125.00','currency'=>'EUR'],'deal',(string)$dealId);
$check($commercial['state']['deal_value']==='125.00','Governed commercial terms missing.');
$due='2035-01-01T10:00:00Z';$due2='2035-01-02T10:00:00Z';
$leadActivity=$runBusiness('lead.followup.schedule',['lead_id'=>$leadId,'title'=>'R1 callback','due_at'=>$due],'lead',(string)$leadId);
$act=$leadActivity['activity_id'];
$runBusiness('lead.activity.reschedule',['lead_id'=>$leadId,'activity_id'=>$act,'due_at'=>$due2],'lead',(string)$leadId);
$runBusiness('lead.activity.complete',['lead_id'=>$leadId,'activity_id'=>$act],'lead',(string)$leadId);
$cancelActivity=$runBusiness('lead.followup.schedule',['lead_id'=>$leadId,'title'=>'R1 cancellation','due_at'=>$due],'lead',(string)$leadId);
$runBusiness('lead.activity.cancel',['lead_id'=>$leadId,'activity_id'=>$cancelActivity['activity_id'],'reason'=>'No longer needed'],'lead',(string)$leadId);
$record=$runBusiness('activity.record',['deal_id'=>$dealId,'activity_type'=>'note','title'=>'R1 native note','body'=>'Observed','completed'=>true],'deal',(string)$dealId);
$follow=$runBusiness('followup.schedule',['deal_id'=>$dealId,'title'=>'R1 Deal follow-up','due_at'=>$due],'deal',(string)$dealId);
$runBusiness('activity.reschedule',['deal_id'=>$dealId,'activity_id'=>$follow['record_id'],'due_at'=>$due2],'deal',(string)$dealId);
$runBusiness('activity.complete',['deal_id'=>$dealId,'activity_id'=>$follow['record_id']],'deal',(string)$dealId);
$cancelDeal=$runBusiness('followup.schedule',['deal_id'=>$dealId,'title'=>'R1 cancelled follow-up','due_at'=>$due],'deal',(string)$dealId);
$runBusiness('activity.cancel',['deal_id'=>$dealId,'activity_id'=>$cancelDeal['record_id'],'reason'=>'No longer needed'],'deal',(string)$dealId);
$propertyType=(int)$db->query('SELECT MIN(id) FROM tn_property_types')->fetchColumn();$propertyLocation=(int)$db->query('SELECT MIN(id) FROM tn_locations')->fetchColumn();
$check($propertyType>0&&$propertyLocation>0,'R1 property vocabulary missing.');
$db->prepare('INSERT INTO tn_properties(organization_id,public_id,slug,title,deal_type,type_id,status,location_id) VALUES (?,?,?,?,"sale",?,"draft",?)')->execute([$org,'R1-'.$nonce,'r1-'.$nonce,'R1 property',$propertyType,$propertyLocation]);
$propertyId=(int)$db->lastInsertId();
$db->prepare('INSERT INTO tn_property_assets(organization_id,asset_id,kind,type_code,lifecycle,legacy_property_id) VALUES (?,?,"unit","apartment","existing",?)')->execute([$org,'r1-asset-'.$nonce,$propertyId]);
$db->prepare('INSERT INTO tn_property_inventory_items(organization_id,inventory_id,asset_id,transaction_type,status,price_amount,price_currency) VALUES (?,?,?,"sale","available",1000,"USD")')->execute([$org,'r1-inv-'.$nonce,'r1-asset-'.$nonce]);
$runBusiness('property_match.record',['deal_id'=>$dealId,'property_id'=>$propertyId,'match_status'=>'suggested','score'=>90,'note'=>'R1 verified property'],'deal',(string)$dealId);
echo "Sales R1 canonical operator intake, lead state, Deal activity and property matching PASS.\n";
$quote=['title'=>'R1 service quote','currency'=>'EUR','valid_until'=>'2035-01-01T00:00:00Z','lines'=>[['kind'=>'service','title'=>'Service','quantity'=>1,'unit_price'=>'125.00']]];
$offer=$runBusiness('offer.create',['deal_id'=>$dealId,...$quote],'deal',(string)$dealId)['offer'];
$offerId=$offer['offer_id'];$identity=['deal_id'=>$dealId,'offer_id'=>$offerId];
$runBusiness('offer.transition',[...$identity,'expected_revision'=>1,'target_status'=>'presented','evidence_reference'=>'r1-email'],'offer',$offerId);
$runBusiness('negotiation.record',[...$identity,'expected_revision'=>2,'kind'=>'objection','note'=>'Alternative requested'],'offer',$offerId);
$runBusiness('offer.revise',[...$identity,'expected_revision'=>3,...$quote,'direction'=>'customer','evidence_reference'=>'r1-counter'],'offer',$offerId);
$runBusiness('offer.revise',[...$identity,'expected_revision'=>4,...$quote,'direction'=>'sales'],'offer',$offerId);
$runBusiness('offer.transition',[...$identity,'expected_revision'=>5,'target_status'=>'presented','evidence_reference'=>'r1-second'],'offer',$offerId);
$runBusiness('offer.transition',[...$identity,'expected_revision'=>6,'target_status'=>'accepted','evidence_reference'=>'r1-accept'],'offer',$offerId);
$wonInput=['deal_id'=>$dealId,'expected_revision'=>$dealSnapshot()['revision'],'target_stage_id'=>$wonStage,'outcome'=>'won'];
$runBusiness('deal.outcome.record',$wonInput,'deal',(string)$dealId);
$check($dealSnapshot()['status']==='closed','Approved terminal edge did not close Deal.');
echo "Sales R1 governed conversion, commercial terms, lead activity and offer history + R2 approval-required Pipeline and WON edge PASS.\n";
// The new owner's eligibility is established through the existing governed team command.
$teamId=$teamResult['team']['team_id'];
$runBusiness('team.manage',['operation'=>'member_set','team_id'=>$teamId,'configuration_version'=>1,
    'user_id'=>(int)$reviewer,'role'=>'MEMBER','member_status'=>'ACTIVE','assignment_enabled'=>true,
    'approval_enabled'=>false,'assignment_weight'=>1],'sales_team_intent',bin2hex(random_bytes(16)));
$assignLead=$runBusiness('lead.create',['full_name'=>'R1 assignment lead','email'=>'r1-assign-'.$nonce.'@example.test'],'sales_intent',bin2hex(random_bytes(16)));
$runBusiness('lead.qualify',['lead_id'=>$assignLead['lead_id'],'expected_status'=>'new','target_status'=>'contacted'],'lead',(string)$assignLead['lead_id']);
$assignDeal=$runBusiness('client_case.create',['lead_id'=>$assignLead['lead_id']],'lead',(string)$assignLead['lead_id'])['case_id'];
$check((int)$businessRead->deal($org,$assignDeal)['assigned_user_id']===(int)$requester,'Assignment fixture must start with requester-owned Deal.');
$assignState=\Domains\Sales\Application\Support\DealStateSnapshot::fromNative($businessDeals->getForStageChange($org,(string)$assignDeal),$org,$assignDeal);
$assignment=$runBusiness('deal.owner.assign',['deal_id'=>$assignDeal,'expected_revision'=>$assignState['revision'],'owner_id'=>(int)$reviewer],'deal',(string)$assignDeal);
$check($assignment['state']['owner_id']===(int)$reviewer&&(int)$businessRead->deal($org,$assignDeal)['assigned_user_id']===(int)$reviewer,'Governed owner assignment readback failed.');
echo "Sales R1 canonical team member configuration and Deal assignment PASS.\n";
// Both machine origins submit a signed proposal only; actual human review and separate
// Plan/Action Approval run through the same canonical bridge and native outcome provider.
$grant->execute([$org,(int)$requester,'sales.admin.agents.manage',$requester]);
$grant->execute([$org,(int)$requester,'sales.director.view',$requester]);
$aiLead=$runBusiness('lead.create',['full_name'=>'AI signed lead','email'=>'ai-signed-'.$nonce.'@example.test'],
    'sales_intent',bin2hex(random_bytes(16)),['intent_origin'=>'ai','extra_steps'=>[[
        'id'=>'next-lead','capability_id'=>'sales.governed.lead.create','capability_version'=>'1.0.0',
        'input'=>['target_type'=>'sales_intent','target_id'=>bin2hex(random_bytes(16)),
            'parameters'=>['full_name'=>'AI signed next lead','email'=>'ai-next-'.$nonce.'@example.test']]
    ]]]);
$check($aiLead['payment']['lead_id']!==$aiLead['next-lead']['lead_id']&&
    (int)$businessRead->lead($org,$aiLead['payment']['lead_id'])['assigned_user_id']===(int)$requester&&
    (int)$businessRead->lead($org,$aiLead['next-lead']['lead_id'])['assigned_user_id']===(int)$requester,'Ordered AI reviewed lead native states differ.');
$cronRun=$runBusiness('order.source.sync.start',['channel'=>'shopify','connection_id'=>$connectionId,'mode'=>'webhooks'],
    'sales_order_connection',$connectionId,['intent_origin'=>'cron']);
$check($businessSync->status($org,(int)$requester,$cronRun['run_id'],null)['counts']['discovery_pending']===1,'CRON reviewed sync not admitted.');
$missingR1=array_values(array_diff(\Domains\Sales\Automation\Action\SalesGovernedBusinessOperations::types(),array_keys($verifiedR1)));
$check($missingR1===[],'Missing canonical R1 native outcome cases: '.implode(', ',$missingR1));
echo "Sales R1 all 28 governed commands + signed AI/CRON review and real independent Plan/Action/Outcome PASS\n";

/* O5/O7 native public Lead -> governed Deal/WON -> governed Order -> Marketing.
   Use the real Sales placement and Lead/Deal read adapters, not a provider or alias fixture. */
$linkedCampaign=$mktCampaigns->createDraft($org,$requester,$mktPolicy->validate([
    'name'=>'O7 linked native conversion','business_objective'=>'Deduplicated captured commercial outcome'
]),'o7-linked-'.$nonce)['id'];
$linkedTags=['utm_source'=>'google','utm_medium'=>'organic','utm_campaign'=>$linkedCampaign];
$linkedPublic=['submission_id'=>bin2hex(random_bytes(16)),'full_name'=>'O7 linked enquiry',
    'email'=>'o7-linked-'.$nonce.'@example.test',...$linkedTags];
$submitted=$businessBus->dispatch(new \App\Application\Sales\Command\ReceivePublicLeadCommand(
    \Kernel\Shared\Domain\OrganizationId::fromString($org),$linkedPublic,'/contacts'));
$check($submitted->ok&&is_int($submitted->data['lead_id']??null),'Native campaign enquiry rejected.');
$linkedLead=$submitted->data['lead_id'];
$linkedNative=$businessRead->lead($org,$linkedLead);
$linkedDeal=(int)$linkedNative['client_case_id'];
$check($linkedDeal>0&&array_intersect_key($linkedNative,$linkedTags)===$linkedTags,'Exact native UTM or Deal linkage missing.');
$linkedSnapshot=static fn()=>\Domains\Sales\Application\Support\DealStateSnapshot::fromNative($businessDeals->getForStageChange($org,(string)$linkedDeal),$org,$linkedDeal);
$runBusiness('deal.owner.assign',['deal_id'=>$linkedDeal,'expected_revision'=>$linkedSnapshot()['revision'],'owner_id'=>(int)$reviewer],'deal',(string)$linkedDeal);
$runBusiness('deal.commercial.set',['deal_id'=>$linkedDeal,'expected_revision'=>$linkedSnapshot()['revision'],'deal_value'=>'50.00','currency'=>'EUR'],'deal',(string)$linkedDeal);
$check($linkedSnapshot()['stage_id']===$from,'Public Deal did not enter the configured initial pipeline.');
$runBusiness('pipeline.stage.manage',['deal_id'=>$linkedDeal,'expected_revision'=>$linkedSnapshot()['revision'],'target_stage_id'=>$to],'deal',(string)$linkedDeal);
$runBusiness('deal.outcome.record',['deal_id'=>$linkedDeal,'expected_revision'=>$linkedSnapshot()['revision'],'target_stage_id'=>$wonStage,'outcome'=>'won'],'deal',(string)$linkedDeal);
$mktSalesSource=new \App\Infrastructure\Marketing\SalesAttributionReadAdapter($businessRead,$active);
$leadFact=$mktSalesSource->lead($mktActor,(string)$linkedLead);
$check($leadFact['deal']['deal_id']===(string)$linkedDeal&&$leadFact['deal']['won']===true,'Native captured Lead/Deal outcome is not linked.');
$mktLeadStore=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingAttributionStore($db);
$mktPaidProvider=new class implements \Domains\Marketing\Application\Contract\PaidMediaProviderInterface {
    public function inspectAccount(string $organizationId,string $providerAccountId,string $credentialRef):array{throw new DomainException('No external calls.');}
    public function hierarchy(string $organizationId,string $providerAccountId,string $credentialRef):array{throw new DomainException('No external calls.');}
    public function insights(string $organizationId,string $providerAccountId,string $credentialRef,string $from,string $to,string $grain):array{throw new DomainException('No external calls.');}
};
$mktPaid=new \Domains\Marketing\Application\Service\MarketingPaidReadService($mktAccounts,$mktPaidProvider);
$mktOps=new \Domains\Marketing\Application\Service\MarketingPaidOperationsReadService($mktPlans,
    new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidOperationJournal($db),
    new \Domains\Marketing\Infrastructure\Meta\MetaPaidExecutionProfiles('{}',false),$mktPaid);
$mktFinanceAnalytics=new \App\Infrastructure\Marketing\FinanceAnalyticsReadAdapter(
    new \Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinanceBudgetRepository($db),
    new \Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinancePaidSettlementRepository($db,new \Domains\Finance\Domain\FinancePaidSettlementPolicy()),$active);
$mktLeadAnalytics=new \Domains\Marketing\Application\Service\MarketingAnalyticsService($mktService,$mktPlans,$mktOps,$mktPaid,$mktLeadStore,$mktSalesSource,$mktFinanceAnalytics,$active);
$leadReceipt=$mktLeadAnalytics->capture($mktActor,$linkedCampaign,null,[(string)$linkedLead],'o7-linked-lead-'.$nonce);
$check($leadReceipt['results'][0]['state']==='RECORDED'&&$mktLeadAnalytics->capture($mktActor,$linkedCampaign,null,[(string)$linkedLead],'o7-linked-lead-'.$nonce)===$leadReceipt,
    'Native Marketing lead capture or replay failed.');
$linkedOrder=$executeWorkflow($prefix.'create',[
    'deal_id'=>$linkedDeal,'currency'=>'EUR','lines'=>[['sku'=>'O7-LINKED','title'=>'Linked service','quantity'=>1,'unit_price'=>'50.00']],
    'total'=>'50.00','attribution'=>$linkedTags
],$requester,$reviewer,[...$config,'target_type'=>'sales_order_intent','target'=>bin2hex(random_bytes(16))])['order']['order_id'];
$check($sales->placement($org,$linkedOrder)['deal_id']===$linkedDeal,'Native immutable placement did not retain the Deal link.');
$linkedCommerce=new \Domains\Marketing\Application\Service\MarketingCommerceAttributionService($mktService,$mktPlans,
    new \App\Infrastructure\Marketing\CommerceOrderReadAdapter($active,$sales,$sales,$sales),
    new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingCommerceAttributionStore($db),$mktLeadStore,$active,
    new \App\Infrastructure\Marketing\CommerceFinancialReadAdapter($active,$realStore),$mktSalesSource);
$linkedReceipt=$linkedCommerce->capture($mktActor,$linkedCampaign,null,[$linkedOrder],'o7-linked-order-'.$nonce);
$check($linkedReceipt['results'][0]['state']==='RECORDED'&&$linkedCommerce->capture($mktActor,$linkedCampaign,null,[$linkedOrder],'o7-linked-order-'.$nonce)===$linkedReceipt,
    'Native linked order attribution or replay failed.');
$linkedFrom=substr($leadFact['created_at'],0,10);
$linkedTo=max($linkedFrom,substr($sales->placement($org,$linkedOrder)['occurred_at'],0,10),substr($leadFact['deal']['closed_at'],0,10));
$linkedReport=static fn()=>$linkedCommerce->report($mktActor,$linkedCampaign,null,$linkedFrom,$linkedTo);
$r=$linkedReport();
$check($r['deduplicated_against_deals']&&$r['deduplication']['captured_leads']===1&&$r['deduplication']['won_deals']===1&&
    $r['deduplication']['placed_orders']===1&&$r['deduplication']['overlapping_won_deals']===1&&$r['deduplication']['unique_outcomes']===1&&
    $r['recognized_revenue']===null&&$r['roas']===null&&$r['net_cash_received']===null,'Real Lead/Deal/Order duplicated or fabricated revenue.');
// Fault injection only: mismatched native immutable relationship must quarantine the order.
$db->prepare('UPDATE sales_orders SET deal_id=NULL WHERE organization_id=? AND order_id=?')->execute([$org,$linkedOrder]);
try {
    $bad=$linkedReport();
    $check(!$bad['deduplicated_against_deals']&&$bad['deduplication']['unique_outcomes']===null&&$bad['unresolved_captured_orders']===1,
        'Divergent placement relationship produced a combined conversion count.');
} finally {
    $db->prepare('UPDATE sales_orders SET deal_id=? WHERE organization_id=? AND order_id=?')->execute([$linkedDeal,$org,$linkedOrder]);
}
$check($linkedReport()===$r&&$sales->placement('foreign-'.$org,$linkedOrder)===null,'Native linked evidence did not recover or leaked across tenants.');
$check(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),$linkedPublic['email']),'Customer contact leaked into Marketing report.');
echo "O5/O7 exact native public UTM -> canonical Deal/WON -> canonical Order -> captured-cohort Marketing dedup, replay, relationship drift and no false revenue PASS\n";

$executeWorkflow('sales.governed.team.manage',['operation'=>'create','code'=>'DENY_'.strtoupper($nonce),'name'=>'Revoked authority'],
    $requester,$reviewer,[...$businessConfig,'target_type'=>'sales_team_intent','target'=>bin2hex(random_bytes(16)),'expect_denied'=>true,
    'after_approval'=>static fn()=>$db->prepare('DELETE FROM sales_user_capabilities WHERE organization_id=? AND user_id=? AND capability="sales.approval.any_team"')->execute([$org,(int)$reviewer])]);
$q=$db->prepare('SELECT COUNT(*) FROM sales_teams WHERE organization_id=? AND code=?');$q->execute([$org,'DENY_'.strtoupper($nonce)]);
$check((int)$q->fetchColumn()===0,'Revoked reviewer permitted native team mutation.');
echo "Sales R1 canonical Goal -> independent Plan and Action approvals -> real lead create/qualify and team configuration -> native Goal outcome and revocation PASS.\n";
