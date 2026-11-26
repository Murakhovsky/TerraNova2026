<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require (getenv('COS_TEST_AUTOLOAD')?:$root.'/vendor/autoload.php');

use App\Infrastructure\Finance\MarketingSpendEvidenceReadAdapter;
use Domains\Finance\Application\Service\ApprovedFinanceBudgetOperations;
use Domains\Finance\Application\Service\ApprovedFinanceMarketingSpendImportOperations;
use Domains\Finance\Application\Service\FinanceSpendObservationService;
use Domains\Finance\Automation\Action\FinanceBudgetActionHandler;
use Domains\Finance\Automation\Action\FinanceMarketingSpendImportActionHandler;
use Domains\Finance\Bootstrap\FinanceDomainModule;
use Domains\Finance\Domain\BudgetControlPolicy;
use Domains\Finance\Domain\FinanceSpendObservationPolicy;
use Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinanceBudgetRepository;
use Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinanceMarketingSpendActionIntentRepository;
use Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinanceSpendObservationRepository;
use Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidReadRepository;
use Kernel\Action\Action;
use Kernel\Policy\PolicyDecision;

$check=static function(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);};
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST')?:'127.0.0.1',getenv('DB_PORT')?:'3306',getenv('DB_DATABASE')?:'cos'),
    getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$org='finance-marketing-approved-'.bin2hex(random_bytes(5));
$alien='finance-marketing-other-'.bin2hex(random_bytes(5));
// The unit/native-ledger tests inject a narrowly scoped admission fixture.
// The real Workflow/Action admission is independently exercised in the canonical E2E.
$admission=new class implements \Kernel\Action\Contract\WorkflowActionWorkerAdmissionInterface {
    public bool $allowed=true;
    public function assertAuthorized(Action $action):void {
        if(!$this->allowed)throw new DomainException('Fixture admission denied.');
    }
};
$moduleCatalog=new \Kernel\Module\ModuleCatalog([
    \Kernel\Module\ModuleDefinition::fromArray(require $root.'/app/Domains/Finance/module.php'),
    \Kernel\Module\ModuleDefinition::fromArray(require $root.'/app/Domains/Marketing/module.php')
]);
$states=new class implements \Kernel\Module\Contract\ModuleStateRepositoryInterface {
    public function enabledOverride(string $org,string $moduleId):?bool{return true;}
    public function setEnabled(string $org,string $moduleId,bool $enabled):void{}
};
$modules=new \Kernel\Module\ActiveModuleResolver($moduleCatalog,$states);
$identities=new class implements \Kernel\Identity\Contract\IdentityResolverInterface {
    public function resolve(\Kernel\Shared\Domain\UserId $userId,\Kernel\Shared\Domain\OrganizationId $organizationId):?\Kernel\Identity\Model\AuthenticatedIdentity {
        return new \Kernel\Identity\Model\AuthenticatedIdentity($userId,$organizationId,\Kernel\Identity\Model\OrganizationRole::fromString('manager'));
    }
};
$tenants=new \Kernel\Tenant\Service\TenantContextFactory(new \App\Security\RoleTenantPermissionResolver());
$keyFor=static fn(string $key):string=>str_starts_with($key,'wf:')?$key:'wf:'.hash('sha256',$key);

$sourceRepo=new MysqlMarketingPaidReadRepository($db);
$financePolicy=new FinanceSpendObservationPolicy();
$financeRepo=new MysqlFinanceSpendObservationRepository($db,$financePolicy);
$intents=new MysqlFinanceMarketingSpendActionIntentRepository($db);
$financeService=new FinanceSpendObservationService($financeRepo,$financePolicy);
$provider=new class implements \Domains\Marketing\Application\Contract\PaidMediaProviderInterface {
    public function inspectAccount(string $org,string $providerAccountId,string $credentialRef):array {throw new RuntimeException('No provider call allowed.');}
    public function hierarchy(string $org,string $providerAccountId,string $credentialRef):array {throw new RuntimeException('No provider call allowed.');}
    public function insights(string $org,string $providerAccountId,string $credentialRef,string $from,string $to,string $grain):array {throw new RuntimeException('No provider call allowed.');}
};
$approved=new ApprovedFinanceMarketingSpendImportOperations(
    new MarketingSpendEvidenceReadAdapter(new \Domains\Marketing\Application\Service\MarketingPaidReadService($sourceRepo,$provider)),
    $financeService,$intents,$admission,$modules);
$handler=new FinanceMarketingSpendImportActionHandler($approved,$identities,$tenants);
$budgetHandler=new FinanceBudgetActionHandler(
    new ApprovedFinanceBudgetOperations(new MysqlFinanceBudgetRepository($db),new BudgetControlPolicy()));
$module=new FinanceDomainModule($budgetHandler,spendImport:$handler);
$check(in_array(FinanceMarketingSpendImportActionHandler::IMPORT,$module->actionTypes(),true)
    &&count($module->actionHandlers())===2,'Finance module did not register the canonical source-only Action owner.');
$policies=$module->policies($org);
$importPolicies=array_values(array_filter($policies,static fn($p)=>
    $p->actionType===FinanceMarketingSpendImportActionHandler::IMPORT));
$check(count($importPolicies)===1
    &&$importPolicies[0]->decision===PolicyDecision::ApprovalRequired,
    'Finance source import must have independent Kernel Policy ApprovalRequired.');
$check(count(array_filter($policies,static fn($p)=>$p->decision!==PolicyDecision::ApprovalRequired))===0,
    'Finance Action policy must not silently auto-approve a mutation.');

$account=$sourceRepo->registerAccount($org,'env://FIN_APPROVED_SOURCE_FIXTURE',[
    'ad_account_id'=>'act_'.random_int(1000000000000,9999999999999),
    'currency'=>'USD','timezone'=>'Europe/Kyiv',
    'connection_status'=>'READ_ONLY','capability_scope'=>['ads_read']
]);
$id=$account['id'];$from='2026-11-01';$to='2026-11-07';
$meta=['campaign_id'=>'919191919','date_start'=>$from,'date_stop'=>$to,
    'spend'=>'14.375000','impressions'=>'172','clicks'=>'9'];
$sourceRepo->importSnapshot($org,$id,$from,$to,[
    'campaigns'=>[['id'=>'919191919','name'=>'Source read fixture','effective_status'=>'PAUSED']],
    'adsets'=>[],'ads'=>[]
],[$meta]);
$observed=$sourceRepo->insights($org,$id,'campaign',$from,$to);
$check(count($observed)===1,'Approved Action source fixture missing campaign snapshot.');
$sourceId=$observed[0]['id'];
$expected=$sourceRepo->spendEvidence($org,$id,$sourceId);
$params=[
    'ad_account_ref'=>$id,'source_snapshot_ref'=>$sourceId,
    'expected_source_revision'=>$expected['source_revision'],
    'expected_source_hash'=>$expected['source_hash']
];
$make=static fn(string $tenant,string $key,array $params,string $targetId)=>
    new Action(
        'action-'.substr(hash('sha256',$key),0,24),$tenant,
        FinanceMarketingSpendImportActionHandler::IMPORT,'marketing_spend_snapshot',$targetId,
        $params,'USER','301','APPROVAL_REQUIRED','MEDIUM',$keyFor($key),
        new DateTimeImmutable('2026-11-20T12:00:00+00:00'),status:\Kernel\Action\ActionStatus::Running,correlationId:'finance-import-approval-trace'
    );
$key=$keyFor('approved-source-import-000001');
$admission->allowed=false;
$check(!$handler->execute($make($org,'denied-admission',$params,$sourceId))->successful,'Rejected admission entered Finance ledger.');
$admission->allowed=true;
$first=$handler->execute($make($org,$key,$params,$sourceId));
$check($first->successful
    &&$first->data['status']==='SOURCE_OBSERVATION_UNRECONCILED'
    &&$first->data['source_revision']===1
    &&!$first->data['finance_expense_confirmed']
    &&!$first->data['budget_mutated']
    &&!$first->data['source_verified_by_provider'],
    'Approved canonical Action misrepresented financial or provider verification.');
$check($financeRepo->reconciliationStatus($org,$financePolicy->scope(
    $id,$from,$to,'USD','Europe/Kyiv',$expected['attribution_hash']))['source_count']===1,
    'Approved Action did not persist one Finance-owned source observation.');
$check(!$db->inTransaction(),'Finance import left a MySQL transaction open.');

$q=$db->prepare('SELECT state,source_snapshot_ref,expected_revision,expected_source_hash,finance_observation_id
    FROM cos_finance_spend_import_action_intents WHERE organization_id=? AND idempotency_key=?');
$q->execute([$org,$key]);$intent=$q->fetch(PDO::FETCH_ASSOC);
$check(is_array($intent)&&$intent['state']==='SUCCEEDED'
    &&$intent['source_snapshot_ref']===$sourceId
    &&(int)$intent['expected_revision']===1&&$intent['expected_source_hash']===$expected['source_hash']
    &&$intent['finance_observation_id']===$first->data['finance_observation_ref'],
    'Approved immutable source fingerprint and Finance intent receipt were not persisted.');

$repeat=$handler->execute($make($org,$key,$params,$sourceId));
$check($repeat->successful&&$repeat->data['idempotent_replay']===true
    &&$repeat->data['finance_observation_ref']===$first->data['finance_observation_ref'],
    'Kernel Action same-key replay created a distinct Finance receipt.');

$sourceRepo->importInsights($org,$id,'campaign',$from,$to,[
    [...$meta,'spend'=>'22.900000','clicks'=>'15']
]);
$new=$sourceRepo->spendEvidence($org,$id,$sourceId);
$check($new['source_revision']===2&&$new['source_hash']!==$expected['source_hash'],
    'Meta source correction was not revisioned.');
$staleKey=$keyFor('approved-source-import-old-v01');
$stale=$handler->execute($make($org,$staleKey,$params,$sourceId));
$check(!$stale->successful,'Finance accepted a source version corrected after approval.');
$oldReplay=$handler->execute($make($org,$key,$params,$sourceId));
$check($oldReplay->successful&&$oldReplay->data['source_revision']===1
    &&$oldReplay->data['idempotent_replay']===true,
    'Completed Action replay must stay pinned to the old approved source revision.');
$reused=$handler->execute($make($org,$key,[
    ...$params,'expected_source_revision'=>2,'expected_source_hash'=>$new['source_hash']
],$sourceId));
$check(!$reused->successful,'Same approved Action key was allowed to import another source revision.');
$keyV2=$keyFor('approved-source-import-000002');
$updated=$handler->execute($make($org,$keyV2,[
    ...$params,'expected_source_revision'=>2,'expected_source_hash'=>$new['source_hash']
],$sourceId));
$check($updated->successful&&!$updated->data['idempotent_replay']
    &&$updated->data['source_revision']===2
    &&$updated->data['finance_observation_ref']===$first->data['finance_observation_ref'],
    'Independently approved source correction did not update the one Finance observation.');
$foreign=$handler->execute($make($alien,'approved-source-import-other',$params,$sourceId));
$check(!$foreign->successful,'Foreign tenant imported source evidence.');
$wrongTarget=$handler->execute($make($org,'approved-source-import-mismatch',$params,'mis-'.str_repeat('a',24)));
$check(!$wrongTarget->successful,'Wrong Action target bypassed snapshot reference binding.');
$badInput=$handler->execute($make($org,'approved-source-import-forged',
    [...$params,'reported_spend_decimal'=>'99999.00'],$sourceId));
$check(!$badInput->successful,'Caller was able to forge reported spend on approved Action.');

$events=$db->prepare('SELECT COUNT(*) FROM cos_finance_spend_evidence_events WHERE organization_id=?');
$events->execute([$org]);$check((int)$events->fetchColumn()===2,
    'One initial source and one correction must produce two Finance evidence events only.');
$ledger=$db->prepare('SELECT COUNT(*) FROM cos_finance_spend_import_action_intents
    WHERE organization_id=? AND state=?');
$ledger->execute([$org,'SUCCEEDED']);$check((int)$ledger->fetchColumn()===2,
    'Expected exactly one accepted intent per independently approved source version.');
$budgets=$db->prepare('SELECT COUNT(*) FROM cos_finance_budget_reservations WHERE organization_id=?');
$budgets->execute([$org]);$check((int)$budgets->fetchColumn()===0,
    'Source-only Finance Action created a budget reservation.');

// Two independent MySQL connections must not let one idempotency key be
// claimed twice while the first writer's source intent is row-locked.
$dbOther=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST')?:'127.0.0.1',getenv('DB_PORT')?:'3306',getenv('DB_DATABASE')?:'cos'),
    getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$dbOther->exec('SET SESSION innodb_lock_wait_timeout=1');
$otherIntents=new MysqlFinanceMarketingSpendActionIntentRepository($dbOther);
$db->beginTransaction();
try {
    $held=$db->prepare('SELECT idempotency_key
        FROM cos_finance_spend_import_action_intents
        WHERE organization_id=? AND idempotency_key=? FOR UPDATE');
    $held->execute([$org,$keyV2]);
    $check($held->fetchColumn()===$keyV2,'Finance approved intent was not lockable.');
    $contended=false;
    try{
        $otherIntents->claim($org,$keyV2,$id,$sourceId,2,$new['source_hash']);
    }catch(PDOException){$contended=true;}
    $check($contended,'Independent connection bypassed Finance Action intent row lock.');
    $check(!$dbOther->inTransaction(),'Contending Finance Action writer leaked a transaction.');
}finally{
    if($db->inTransaction())$db->rollBack();
}
$afterRelease=$otherIntents->claim($org,$keyV2,$id,$sourceId,2,$new['source_hash']);
$check($afterRelease['state']==='SUCCEEDED'
    &&$afterRelease['finance_observation_id']===$first->data['finance_observation_ref'],
    'After lock release Finance Action did not replay exact approved receipt.');

// An interrupted CLAIMED attempt is recoverable with the same source fingerprint,
// but cannot mutate another source or skip its source version pinning.
$recoverKey=$keyFor('approved-source-recovery-0003');
$claimed=$otherIntents->claim($org,$recoverKey,$id,$sourceId,2,$new['source_hash']);
$check($claimed['state']==='CLAIMED','Recovery fixture did not create an immutable Action claim.');
$deniedConflict=false;
try{$otherIntents->claim($org,$recoverKey,$id,$sourceId,1,$expected['source_hash']);}
catch(DomainException){$deniedConflict=true;}
$check($deniedConflict,'Incomplete Action intent was repurposed for a different approved version.');
$recovered=$handler->execute($make($org,$recoverKey,[
    ...$params,'expected_source_revision'=>2,'expected_source_hash'=>$new['source_hash']
],$sourceId));
$check($recovered->successful
    &&$recovered->data['finance_observation_ref']===$first->data['finance_observation_ref'],
    'Native intent recovery with admitted identical fingerprint failed.');
$q->execute([$org,$recoverKey]);$recoveredIntent=$q->fetch(PDO::FETCH_ASSOC);
$check($recoveredIntent['state']==='SUCCEEDED','Recovered Action lacks a durable completion receipt.');
$events->execute([$org]);$check((int)$events->fetchColumn()===2,
    'Recovering an already observed provider revision created a duplicate Finance event.');

echo "Finance source Action native MySQL with isolated admission fixture, Policy declaration, pinned revisions, durable intent and tenant fences PASS\n";
