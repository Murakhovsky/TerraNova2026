<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$autoload=getenv('COS_TEST_AUTOLOAD')?:$root.'/vendor/autoload.php';
require $autoload;

use Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinanceBudgetRepository;

$assert=static function(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);};
$host=getenv('DB_HOST')?:'127.0.0.1';
$port=getenv('DB_PORT')?:'3306';
$name=getenv('DB_DATABASE')?:'cos';
$user=getenv('DB_USERNAME')?:'cos';
$password=getenv('DB_PASSWORD')?:'';
$db=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$password,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$repo=new MysqlFinanceBudgetRepository($db);
$orgA='finance-f1-a-'.bin2hex(random_bytes(5));
$orgB='finance-f1-b-'.bin2hex(random_bytes(5));
$periodStart=(new DateTimeImmutable('today',new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
$periodEnd=(new DateTimeImmutable('today',new DateTimeZone('UTC')))->modify('+14 days')->format('Y-m-d');
$draft=['name'=>'Marketing budget integration','source_domain'=>'marketing','source_ref'=>'mkt-proof',
    'currency'=>'USD','period_start'=>$periodStart,'period_end'=>$periodEnd,'limit_minor'=>10000];
$created=$repo->createDraft($orgA,'101',$draft,'finance-draft-1');
$id=$created['id'];
$assert(preg_match('/^finb-[a-f0-9]{24}$/D',$id)===1,'Canonical Finance budget ID');
$assert($created['state']==='DRAFT'&&$created['revision']===1&&$created['available_minor']===10000,'Draft state invalid');
$repeat=$repo->createDraft($orgA,'101',$draft,'finance-draft-1');
$assert($repeat['id']===$id,'Duplicate draft wrote a second envelope');
try{$repo->createDraft($orgA,'101',[...$draft,'limit_minor'=>11000],'finance-draft-1');}
catch(DomainException){$conflict=true;}
$assert($conflict??false,'Finance draft key/payload mismatch was accepted');

$assert($repo->envelope($orgB,$id)===null,'Tenant B read tenant A Finance budget');
try{$repo->reservations($orgB,$id);}catch(DomainException){$tenantBlocked=true;}
$assert($tenantBlocked??false,'Cross-tenant reservation listing must not return silent emptiness');

$active=$repo->activate($orgA,$id,'102','action-approve-1','trace-1',1,'finance-activate-1');
$assert($active['state']==='ACTIVE'&&$active['revision']===2,'Activation missing revision');
$again=$repo->activate($orgA,$id,'102','action-approve-1','trace-1',1,'finance-activate-1');
$assert($again['revision']===2,'Idempotent activation advanced revision');
try{$repo->reserve($orgB,$id,'104','a-tenant-b','trace-b',[
    'source_domain'=>'marketing','source_operation_key'=>'meta-op-tenant-b','amount_minor'=>1,
    'reason'=>'wrong tenant','expected_revision'=>2
],'finance-reserve-tenant-b');}
catch(DomainException){$denied=true;}
$assert($denied??false,'Other tenant reserved Finance funds');

$p1=['source_domain'=>'marketing','source_operation_key'=>'meta-op-campaign-1','amount_minor'=>7000,
    'reason'=>'approved Meta spend','expected_revision'=>2];
$r1=$repo->reserve($orgA,$id,'103','action-reserve-1','trace-reserve-1',$p1,'finance-reserve-1');
$assert($r1['state']==='RESERVED'&&$r1['amount_minor']===7000,'First reservation not recorded');
$retry=$repo->reserve($orgA,$id,'103','action-reserve-1','trace-reserve-1',$p1,'finance-reserve-1');
$assert($retry['id']===$r1['id'],'Duplicate reservation issued new hold');
$budget=$repo->envelope($orgA,$id);
$assert($budget['reserved_minor']===7000&&$budget['available_minor']===3000&&$budget['revision']===3,'Reservation ledger drift');

try{$repo->reserve($orgA,$id,'103','action-replay','trace-replay',
    [...$p1,'expected_revision'=>3],'finance-reserve-2');}
catch(DomainException){$sameOperationBlocked=true;}
$assert($sameOperationBlocked??false,'Same provider operation reserved twice with a distinct action');
try{$repo->reserve($orgA,$id,'103','action-overspend','trace-over',
    ['source_domain'=>'marketing','source_operation_key'=>'meta-op-overspend','amount_minor'=>4000,
    'reason'=>'should exceed limit','expected_revision'=>3],'finance-reserve-overspend');}
catch(DomainException){$overspendBlocked=true;}
$assert($overspendBlocked??false,'Overspend budget hold succeeded');

$r2=$repo->reserve($orgA,$id,'103','action-reserve-2','trace-r2',
    ['source_domain'=>'marketing','source_operation_key'=>'meta-op-campaign-2','amount_minor'=>2000,
    'reason'=>'second approved placement','expected_revision'=>3],'finance-reserve-2');
$assert($repo->envelope($orgA,$id)['available_minor']===1000,'Available minor units incorrect');

$released=$repo->release($orgA,$r1['id'],'104','action-release-1','trace-release','operator halted campaign','finance-release-1');
$assert($released['state']==='RELEASED','Finance reservation release did not persist');
$repo->release($orgA,$r1['id'],'104','action-release-1','trace-release','operator halted campaign','finance-release-1');
$budget=$repo->envelope($orgA,$id);
$assert($budget['reserved_minor']===2000&&$budget['available_minor']===8000&&$budget['revision']===5,'Duplicate release changed available funds');

$frozen=$repo->freeze($orgA,$id,'104','action-freeze-1','trace-freeze','emergency stop',5,'finance-freeze-1');
$assert($frozen['state']==='FROZEN','Emergency stop missing');
try{$repo->reserve($orgA,$id,'105','action-reserve-frozen','trace-reserve-frozen',
    ['source_domain'=>'marketing','source_operation_key'=>'meta-op-after-freeze','amount_minor'=>1,
    'reason'=>'must not reserve','expected_revision'=>6],'finance-reserve-frozen');}
catch(DomainException){$frozenBlocked=true;}
$assert($frozenBlocked??false,'FROZEN budget still permits new reservation');
$repo->release($orgA,$r2['id'],'104','action-release-2','trace-r2','release after freeze','finance-release-2');
$final=$repo->envelope($orgA,$id);
$assert($final['reserved_minor']===0&&$final['available_minor']===10000
    &&$final['committed_minor']===0,'Finance final balance drift');
$events=$db->prepare('SELECT event_type FROM cos_finance_budget_events WHERE organization_id=? AND envelope_id=? ORDER BY created_at,id');
$events->execute([$orgA,$id]);$types=$events->fetchAll(PDO::FETCH_COLUMN);
$assert(count($types)===7,'Expected seven immutable state events without replay duplication: '.implode(',',$types));
$assert(count($repo->reservations($orgA,$id))===2,'Expected two unique reservations only');
echo "Finance F1 MySQL idempotency, tenant isolation, anti-overspend, freeze and immutable ledger PASS\n";

