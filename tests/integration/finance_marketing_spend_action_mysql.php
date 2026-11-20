<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require (getenv('COS_TEST_AUTOLOAD')?:$root.'/vendor/autoload.php');

use App\Infrastructure\Finance\ApprovedMarketingSpendEvidenceReadAdapter;
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
$sourceRepo=new MysqlMarketingPaidReadRepository($db);
$financePolicy=new FinanceSpendObservationPolicy();
$financeRepo=new MysqlFinanceSpendObservationRepository($db,$financePolicy);
$intents=new MysqlFinanceMarketingSpendActionIntentRepository($db);
$financeService=new FinanceSpendObservationService($financeRepo,$financePolicy);
$approved=new ApprovedFinanceMarketingSpendImportOperations(
    new ApprovedMarketingSpendEvidenceReadAdapter($sourceRepo),
    $financeService,$intents);
$handler=new FinanceMarketingSpendImportActionHandler($approved);
$budgetHandler=new FinanceBudgetActionHandler(
    new ApprovedFinanceBudgetOperations(new MysqlFinanceBudgetRepository($db),new BudgetControlPolicy()));
$module=new FinanceDomainModule($budgetHandler,$handler);
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
        $params,'human_ui','301','async','MEDIUM',$key,
        new DateTimeImmutable('2026-11-20T12:00:00+00:00'),correlationId:'finance-import-approval-trace'
    );
$key='approved-source-import-000001';
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
$staleKey='approved-source-import-old-v01';
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
$keyV2='approved-source-import-000002';
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
echo "Finance approved Action real MySQL, Policy ApprovalRequired, pinned revisions, durable idempotency and tenant fences PASS\n";
