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
$catalog=new ModuleCatalog([ModuleDefinition::fromArray(require $root.'/app/Domains/Sales/module.php'),ModuleDefinition::fromArray(require $root.'/app/Domains/Finance/module.php')]);
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


/* Canonical Workflow integration candidate. Plan trust is a pinned fixture,
 * not a production Plan approval. Real claim, worker gate, Finance Action,
 * independent Approval, receipt verification and checkpoint use MySQL. */
$wfDb=\Doctrine\DBAL\DriverManager::getConnection([
    'driver'=>'pdo_mysql','host'=>getenv('DB_HOST')?:'127.0.0.1','port'=>(int)(getenv('DB_PORT')?:3306),
    'dbname'=>getenv('DB_DATABASE')?:'cos','user'=>getenv('DB_USERNAME')?:'cos',
    'password'=>getenv('DB_PASSWORD')?:'','charset'=>'utf8mb4',
]);
$capabilities=new \Kernel\Module\CanonicalCapabilityCatalog($catalog);
$executeWorkflow=static function(string $type,array $p,string $initiator,string $approver)use(
    $wfDb,$db,$org,$id,$catalog,$capabilities,$active,$domains,$handler,$approvals,$tx,$realAuthority,$check,$deny
):array{
    $token=bin2hex(random_bytes(8));
    $actor=new \Kernel\Tenant\Model\TenantContext(\Kernel\Shared\Domain\UserId::fromString($initiator),
        \Kernel\Shared\Domain\OrganizationId::fromString($org),\Kernel\Identity\Model\OrganizationRole::fromString('manager'),
        [\Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::ACCESS),
         \Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::MANAGE)]);
    $identity=new \Platform\Workflow\Model\WorkflowRunIdentity($org,'fin-wf-'.$token);
    $plan='fin-plan-'.$token;$approval='fin-plan-approval-'.$token;$digest=hash('sha256',$plan);
    $definition=new \Platform\Workflow\Model\WorkflowDefinitionSnapshot('test.finance.order','1.0.0',[[
        'id'=>'payment','capability_id'=>$type,'capability_version'=>'1.0.0','owner_domain'=>'finance',
        'execution_binding'=>'action:'.$type,'side_effect_level'=>'financial','depends_on'=>[],
        'input'=>['target_type'=>'sales_order','target_id'=>$id,'parameters'=>$p],
    ]]);
    $draft=new \Platform\Workflow\Model\WorkflowRunDraft($identity,$definition->id,$definition->version,
        hash('sha256',json_encode($p,JSON_THROW_ON_ERROR)),$initiator,'fin-corr-'.$token,$plan,$approval,$digest,$definition);
    $planEvidence=new class($identity,$plan,$digest,$approval) implements \Platform\Workflow\Contract\WorkflowPlanApprovalEvidenceInterface{
        public function __construct(private \Platform\Workflow\Model\WorkflowRunIdentity $run,private string $plan,private string $digest,private string $approval){}
        public function assertTrusted(\Platform\Workflow\Model\WorkflowRunIdentity $identity,string $planId,string $planDigest,string $approvalReference):void
        {if($identity!=$this->run||$planId!==$this->plan||$planDigest!==$this->digest||$approvalReference!==$this->approval)throw new DomainException('Pinned test Plan proof mismatch.');}
    };
    $receipt=new \App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier($wfDb,$planEvidence);
    $claimGate=new \App\Persistence\Workflow\DoctrineWorkflowActionClaimAdmission($wfDb,$planEvidence,$capabilities,$active);
    $store=new \App\Persistence\Workflow\DoctrineWorkflowExecutionStore($wfDb,$receipt,$claimGate);
    $worker=new \App\Persistence\Workflow\DoctrineWorkflowActionWorkerAdmission($wfDb,$planEvidence,$capabilities,$active,
        new \App\Persistence\Workflow\WorkflowPredecessorReceiptVerifier($receipt,new \App\Persistence\Workflow\FailClosedWorkflowNonActionReceiptEvidence()),
        new \Infrastructure\Platform\Persistence\MySql\Policy\MysqlPolicyRepository($db),
        new \Kernel\Policy\Service\PolicyEngine(new \Kernel\Rule\Service\ConditionEvaluator()),
        new \Kernel\Policy\Service\PolicyContextBuilder($domains),
        new \App\Infrastructure\Persistence\MySql\MysqlIdentityRepository($db),
        new \Kernel\Tenant\Service\TenantContextFactory(new \App\Security\RoleTenantPermissionResolver()));
    $actions=new \Kernel\Action\Service\ActionService(new \Infrastructure\Platform\Persistence\MySql\Action\MysqlActionRepository($db),
        new \Kernel\Action\Service\ActionExecutor([$handler],new \Kernel\Action\Service\ModuleActionExecutionGate($domains,$active,null,$worker)));
    $policy=new \Kernel\Policy\Service\ActionPolicyService($actions,new \Infrastructure\Platform\Persistence\MySql\Policy\MysqlPolicyRepository($db),
        new \Infrastructure\Platform\Persistence\MySql\Policy\MysqlPolicyEvaluationRepository($db),$approvals,
        new \Kernel\Policy\Service\PolicyEngine(new \Kernel\Rule\Service\ConditionEvaluator()),$tx,null,$domains,$active);
    $approvalService=new \Kernel\Approval\Service\ApprovalService($approvals,$actions,$tx,null,null,$realAuthority);
    $store->create($draft,$actor);
    $store->compareAndTransition(new \Platform\Workflow\Model\WorkflowRunTransition(
        new \Platform\Workflow\Model\WorkflowRunCommand($identity,$actor,1,$draft->correlationId),
        \Kernel\Workflow\Model\WorkflowStatus::CREATED,\Kernel\Workflow\Model\WorkflowStatus::RUNNING,'Finance candidate fixture'));
    $step=$store->steps($identity)[0];
    $action=$policy->submit($org,new \Kernel\Action\ActionProposal($type,'sales_order',$id,$p,'USER',
        $initiator,'APPROVAL_REQUIRED','HIGH','wf:'.$step->idempotencyKey),$draft->correlationId);
    $check($action->status===\Kernel\Action\ActionStatus::PendingApproval,'Workflow Finance Action must await human approval.');
    $claim=$store->claim(new \Platform\Workflow\Model\WorkflowStepClaim(
        new \Platform\Workflow\Model\WorkflowRunCommand($identity,$actor,2,$draft->correlationId),'payment','finance-workflow-fixture',120));
    $check($claim->state==='claimed'&&$claim->claimToken!==null,'Financial Workflow claim missing.');
    $q=$db->prepare('SELECT id FROM cos_approvals WHERE organization_id=? AND action_id=?');$q->execute([$org,$action->id]);$approvalId=(string)$q->fetchColumn();$q->closeCursor();
    $check($approvalId!=='','Workflow Action approval missing.');
    $deny(fn()=>$approvalService->approve($org,$approvalId,$initiator));
    $check($actions->executeOutcome($org,$action->id,'unapproved-worker')===null,'Unapproved Workflow Action executed.');
    $approvalService->approve($org,$approvalId,$approver);
    $out=$actions->executeOutcome($org,$action->id,'finance-workflow-worker');
    $check($out!==null&&$out->result->successful&&$out->action()->status===\Kernel\Action\ActionStatus::Completed,'Financial Workflow worker Action failed.');
    $check($actions->executeOutcome($org,$action->id,'replay-worker')===null,'Completed financial Action replayed.');
    $store->checkpoint(new \Platform\Workflow\Model\WorkflowStepCheckpoint(
        new \Platform\Workflow\Model\WorkflowRunCommand($identity,$actor,3,$draft->correlationId),'payment',$claim->claimToken,
        \Kernel\Workflow\Model\StepStatus::COMPLETED,'action:'.$action->id));
    $check($store->steps($identity)[0]->state==='completed','Financial Action receipt was not checkpointed.');
    return $out->result->data;
};
$wfPayload=[...$payload,'amount'=>'60.00','source_reference'=>'workflow-capture'];
$wfRegistered=$executeWorkflow('finance.order.payment.register',$wfPayload,$requester,$reviewer);
$check($realStore->financialEvidence($org,$id)['net_collected_amount']==='40.00','Workflow registration incorrectly changed confirmed collection.');
$executeWorkflow('finance.order.payment.reconcile',[...$wfPayload,'operation_id'=>$wfRegistered['finance_reference'],
    'statement_reference'=>'workflow-statement-line','expected_revision'=>1],$reviewer,$requester);
$check($realStore->financialEvidence($org,$id)['net_collected_amount']==='100.00','Financial Workflow reconciliation did not settle captured evidence.');
echo "Finance actual Action + canonical financial Workflow claim, worker, receipt and checkpoint candidate PASS (pinned Plan fixture; no production cutover).\n";
