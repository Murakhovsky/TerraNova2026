<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require (getenv('COS_TEST_AUTOLOAD')?:$root.'/vendor/autoload.php');

use Domains\Finance\Domain\FinanceSpendObservationPolicy;
use Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinanceSpendObservationRepository;

$check=static function(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);};
$denied=static function(callable $operation,string $why):void{
    try{$operation();}catch(DomainException){return;}
    throw new RuntimeException($why);
};
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST')?:'127.0.0.1',getenv('DB_PORT')?:'3306',getenv('DB_DATABASE')?:'cos'),
    getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
$policy=new FinanceSpendObservationPolicy();
$repo=new MysqlFinanceSpendObservationRepository($db,$policy);
$org='finance-spend-a-'.bin2hex(random_bytes(5));
$other='finance-spend-b-'.bin2hex(random_bytes(5));
$base=[
    'ad_account_ref'=>'mads-'.str_repeat('a',24),
    'source_snapshot_ref'=>'mis-'.str_repeat('b',24),
    'grain'=>'campaign','external_id'=>'23456789',
    'window_start'=>'2026-11-10','window_end'=>'2026-11-16',
    'timezone'=>'UTC','currency'=>'USD',
    'attribution_hash'=>str_repeat('c',64),
    'source_hash'=>str_repeat('d',64),
    'source_revision'=>1,'source_status'=>'PROVISIONAL',
    'reported_spend_decimal'=>'100.250000',
];
$one=$repo->record($org,$base,'finance-spend-initial');
$check($one['source_revision']===1
    &&$one['reported_spend_decimal']==='100.25'
    &&!$one['finance_expense_confirmed']
    &&$one['evidence_verification']==='UNVERIFIED_SOURCE','Imported observation was overstated as Expense');
$repeat=$repo->record($org,$base,'finance-spend-retry');
$check($one['id']===$repeat['id'],'Exact source snapshot replay must be idempotent');
$denied(static fn()=> $repo->record($org,[...$base,'source_hash'=>str_repeat('e',64)],'finance-spend-conflict'),
    'Same source revision changed facts silently');
$denied(static fn()=> $repo->record($org,[...$base,'source_revision'=>0],'finance-spend-stale'),
    'Invalid source version accepted');
$revised=[...$base,
    'source_hash'=>str_repeat('e',64),'source_revision'=>2,
    'source_status'=>'REVISED','reported_spend_decimal'=>'110.125000'
];
$second=$repo->record($org,$revised,'finance-spend-revision');
$check($second['id']===$one['id']&&$second['source_revision']===2
    &&$second['reported_spend_decimal']==='110.125','Provider revision must update native observation');
$denied(static fn()=> $repo->record($org,$base,'finance-spend-old-retry'),
    'Old provider snapshot revision downgraded spend');
$denied(static fn()=> $repo->record($org,[...$revised,
    'source_snapshot_ref'=>'mis-'.str_repeat('f',24),'source_revision'=>3],'finance-spend-ref-drift'),
    'Same reporting scope allowed a conflicting source ID');

$secondCampaign=[...$base,'source_snapshot_ref'=>'mis-'.str_repeat('f',24),
    'external_id'=>'98765432','source_hash'=>str_repeat('1',64),'reported_spend_decimal'=>'8.000000'];
$repo->record($org,$secondCampaign,'finance-spend-campaign-2');
$scope=$policy->scope($base['ad_account_ref'],$base['window_start'],$base['window_end'],
    $base['currency'],$base['timezone'],$base['attribution_hash']);
$status=$repo->reconciliationStatus($org,$scope);
$check($status['status']==='UNRECONCILED'
    &&$status['source_count']===2&&$status['unverified_count']===2
    &&$status['source_reported_spend_decimal']==='118.125'
    &&!$status['finance_expense_confirmed']&&!$status['invoice_reconciled']
    &&!$status['budget_exposure_coverage_confirmed'],'Finance reconciliation read fabricated accounting facts');
$otherScope=$repo->reconciliationStatus($other,$scope);
$check($otherScope['status']==='NO_OBSERVATIONS'&&$otherScope['source_count']===0,
    'Cross-tenant provider observation was exposed');
$wrongCurrency=$repo->reconciliationStatus($org,[...$scope,'currency'=>'EUR']);
$check($wrongCurrency['source_count']===0,'Finance spend aggregation mixed currencies');
$wrongAttribution=$repo->reconciliationStatus($org,[...$scope,'attribution_hash'=>str_repeat('a',64)]);
$check($wrongAttribution['source_count']===0,'Finance spend aggregation mixed attribution settings');
$wrongWindow=$repo->reconciliationStatus($org,[...$scope,'window_start'=>'2026-11-11']);
$check($wrongWindow['source_count']===0,'Finance spend aggregation mixed reporting windows');

$q=$db->prepare('SELECT COUNT(*) FROM cos_finance_spend_evidence_events WHERE organization_id=?');
$q->execute([$org]);$check((int)$q->fetchColumn()===3,'Spend evidence ledger has duplicate or missing revision events');
echo "Finance F1 source spend evidence MySQL tenant, revisions, idempotency, provenance and exact-scope reconciliation PASS\n";

