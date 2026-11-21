<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require (getenv('COS_TEST_AUTOLOAD')?:$root.'/vendor/autoload.php');

use Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinanceBudgetRepository;

$check=static function(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);};
$dsn=sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST')?:'127.0.0.1',
    getenv('DB_PORT')?:'3306',
    getenv('DB_DATABASE')?:'cos');
$username=getenv('DB_USERNAME')?:'cos';$password=getenv('DB_PASSWORD')?:'';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$connA=new PDO($dsn,$username,$password,$options);
$connB=new PDO($dsn,$username,$password,$options);
$repoA=new MysqlFinanceBudgetRepository($connA);
$repoB=new MysqlFinanceBudgetRepository($connB);
$org='finance-f1-concurrency-'.bin2hex(random_bytes(5));
$day=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$draft=[
    'name'=>'Concurrent Marketing reservations','source_domain'=>'marketing','source_ref'=>'mkt-multi-01',
    'currency'=>'USD','period_start'=>$day->modify('-1 day')->format('Y-m-d'),
    'period_end'=>$day->modify('+10 days')->format('Y-m-d'),'limit_minor'=>10000
];
$envelope=$repoA->createDraft($org,'301',$draft,'finance-locked-draft-01');
$id=$envelope['id'];
$active=$repoA->activate($org,$id,'302','finance-approved-activation','trace-lock-1',1,'finance-lock-activate');
$check($active['revision']===2&&$active['state']==='ACTIVE','Finance activation precondition failed.');

// A locks the exact envelope. B is an independent connection trying a reserve simultaneously.
// B must time out, rollback fully, and never claim funds/issue an Action receipt.
$connB->exec('SET SESSION innodb_lock_wait_timeout=1');
$connA->beginTransaction();
try{
    $query=$connA->prepare('SELECT id FROM cos_finance_budget_envelopes WHERE organization_id=? AND id=? FOR UPDATE');
    $query->execute([$org,$id]);
    $check($query->fetchColumn()===$id,'Cannot acquire Finance envelope row lock.');
    $denied=false;
    try{
        $repoB->reserve($org,$id,'303','action-waiting-01','trace-waiting-01',[
            'source_domain'=>'marketing','source_operation_key'=>'meta-campaign-lock-01',
            'amount_minor'=>7000,'reason'=>'Contention test, awaiting approved writer',
            'expected_revision'=>2
        ],'finance-waiting-reserve-01');
    }catch(PDOException $e){
        // InnoDB lock wait timeout is a retryable DB condition, not evidence of a provider result.
        $denied=true;
    }
    $check($denied,'Concurrent Finance writer bypassed a locked envelope.');
    $check(!$connB->inTransaction(),'Finance writer did not roll back after lock contention.');
    $query=$connA->prepare('SELECT reserved_minor,revision FROM cos_finance_budget_envelopes WHERE organization_id=? AND id=?');
    $query->execute([$org,$id]);$locked=$query->fetch(PDO::FETCH_ASSOC);
    $check((int)$locked['reserved_minor']===0&&(int)$locked['revision']===2,
        'Failed contested attempt modified the Finance ledger.');
}finally{
    if($connA->inTransaction())$connA->commit();
}
$check(!$connA->inTransaction(),'Lock holder leaked a transaction.');
$noReceipt=$connA->prepare("SELECT COUNT(*) FROM cos_finance_budget_intents
    WHERE organization_id=? AND operation='reserve' AND idempotency_key=?");
$noReceipt->execute([$org,'finance-waiting-reserve-01']);
$check((int)$noReceipt->fetchColumn()===0,'Lock-failed writer left a false idempotency receipt.');

// Retry after lock release: first writer reserves 70%; second cannot reserve another 40%.
$first=$repoB->reserve($org,$id,'303','action-waiting-01','trace-waiting-01',[
    'source_domain'=>'marketing','source_operation_key'=>'meta-campaign-lock-01',
    'amount_minor'=>7000,'reason'=>'Contention test, awaiting approved writer','expected_revision'=>2
],'finance-waiting-reserve-01');
$check($first['state']==='RESERVED'&&$first['amount_minor']===7000,'Post-lock Finance reservation failed.');
$blocked=false;
try{
    $repoA->reserve($org,$id,'304','action-compete-02','trace-compete-02',[
        'source_domain'=>'marketing','source_operation_key'=>'meta-campaign-lock-02',
        'amount_minor'=>4000,'reason'=>'Second approved campaign exceeds available funds',
        'expected_revision'=>3
    ],'finance-competing-reserve-02');
}catch(DomainException){$blocked=true;}
$check($blocked,'Concurrent envelopes oversubscribed 10000 minor units.');
$state=$repoA->envelope($org,$id);
$check($state['reserved_minor']===7000 && $state['available_minor']===3000 && $state['revision']===3,
    'Concurrent writer changed Finance balance without an accepted reservation.');
$second=$repoA->reserve($org,$id,'304','action-compete-03','trace-compete-03',[
    'source_domain'=>'marketing','source_operation_key'=>'meta-campaign-lock-03',
    'amount_minor'=>2500,'reason'=>'Second bounded competing campaign',
    'expected_revision'=>3
],'finance-competing-reserve-03');
$check($second['state']==='RESERVED'&&$repoB->envelope($org,$id)['available_minor']===500,
    'Sub-limit second Finance reservation cannot be reconciled across connections.');
$repeat=$repoB->reserve($org,$id,'303','action-waiting-01','trace-waiting-01',[
    'source_domain'=>'marketing','source_operation_key'=>'meta-campaign-lock-01',
    'amount_minor'=>7000,'reason'=>'Contention test, awaiting approved writer','expected_revision'=>2
],'finance-waiting-reserve-01');
$check($repeat['id']===$first['id']&&$repoA->envelope($org,$id)['reserved_minor']===9500,
    'Idempotent Finance retry after lock release double-reserved funds.');
$event=$connA->prepare("SELECT COUNT(*) FROM cos_finance_budget_events
    WHERE organization_id=? AND event_type='FUNDS_RESERVED'");
$event->execute([$org]);$check((int)$event->fetchColumn()===2,'Expected exactly two committed reservation events.');
echo "Finance F1 MySQL two-connection lock wait, rollback, oversubscription and idempotent retry PASS\n";
