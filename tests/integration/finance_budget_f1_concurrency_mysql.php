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
// A second envelope dedicated to an immutable Marketing launch plan.
$plan='mkpl-'.bin2hex(random_bytes(12));
$ex=$repoA->createDraft($org,'301',[...$draft,'source_ref'=>$plan,'limit_minor'=>5000],'exposure-draft-01');
$repoA->activate($org,$ex['id'],'302','exposure-envelope-approval','exposure-trace',1,'exposure-activate-01');
$hold=$repoA->reserve($org,$ex['id'],'303','exposure-reservation-approval','exposure-trace',[
    'source_domain'=>'marketing','source_operation_key'=>'mkt-paid:'.$plan.':activate','amount_minor'=>5000,
    'reason'=>'Dedicated exposure','expected_revision'=>2],'exposure-reserve-01');
$input=['source_ref'=>$plan,'plan_hash'=>str_repeat('a',64),'currency'=>'USD','period_start'=>$draft['period_start'],
    'period_end'=>$draft['period_end'],'amount_minor'=>5000,'expected_revision'=>3,'source_operation_key'=>'mkt-paid:'.$plan.':activate'];
$connA->beginTransaction();
try{
    $q=$connA->prepare('SELECT id FROM cos_finance_budget_envelopes WHERE organization_id=? AND id=? FOR UPDATE');
    $q->execute([$org,$ex['id']]);
    $timeout=false;
    try{$repoB->claimExposure($org,$ex['id'],$hold['id'],'303','claim-1','exposure-trace',$input);}
    catch(PDOException){$timeout=true;}
    $check($timeout&&!$connB->inTransaction()&&$repoA->exposure($org,$hold['id'])===null,'Claim bypassed envelope lock.');
}finally{$connA->commit();}
$claimed=$repoB->claimExposure($org,$ex['id'],$hold['id'],'303','claim-1','exposure-trace',$input);
$check($claimed['fresh']===true&&$repoA->envelope($org,$ex['id'])['reserved_minor']===5000,'Exposure claim moved or lost funds.');
$replayed=$repoA->claimExposure($org,$ex['id'],$hold['id'],'303','claim-1','exposure-trace',$input);
$check($replayed['fresh']===false,'Replay grants external execution.');
foreach([
    fn()=>$repoA->claimExposure($org,$ex['id'],$hold['id'],'303','claim-2','exposure-trace',$input),
    fn()=>$repoA->release($org,$hold['id'],'304','release-claimed','trace','Unsafe release','claimed-release-01'),
    fn()=>$repoA->claimExposure('foreign-'.$org,$ex['id'],$hold['id'],'303','claim-3','exposure-trace',$input)
] as $unsafe){
    $denied=false;try{$unsafe();}catch(DomainException){$denied=true;}$check($denied,'Unsafe exposure or release accepted.');
}
$repoB->assertActiveExposure($org,$ex['id'],$hold['id'],'claim-1');
$repoA->freeze($org,$ex['id'],'304','freeze-claimed','trace','Emergency freeze',4,'exposure-freeze-01');
$check($repoB->envelope($org,$ex['id'])['state']==='FROZEN'&&$repoB->envelope($org,$ex['id'])['reserved_minor']===5000,
    'Freeze released potentially exposed funds.');
$denied=false;try{$repoB->assertActiveExposure($org,$ex['id'],$hold['id'],'claim-1');}
catch(DomainException){$denied=true;}$check($denied,'Frozen exposure passed last-moment activation check.');
echo "Finance F1 MySQL two-connection lock wait, rollback, oversubscription and idempotent retry PASS\n";

// Settlement is tested against a historical synthetic paid exposure; no provider call.
$settleOrg='finance-settle-'.bin2hex(random_bytes(6));
$settlePlan='mkpl-'.bin2hex(random_bytes(12));$settleEnvelope='finb-'.bin2hex(random_bytes(12));$settleHold='finr-'.bin2hex(random_bytes(12));
$activateAction=bin2hex(random_bytes(16));$settleAction=bin2hex(random_bytes(16));$planHash=str_repeat('b',64);
$periodStart='2020-01-01';$periodEnd='2020-01-03';
$connA->prepare("INSERT INTO cos_finance_budget_envelopes (organization_id,id,owner_id,source_domain,source_ref,name,currency,period_start,period_end,limit_minor,reserved_minor,state,revision) VALUES (?,?,'301','marketing',?,'Settlement fixture','USD',?,?,5000,5000,'FROZEN',4)")
    ->execute([$settleOrg,$settleEnvelope,$settlePlan,$periodStart,$periodEnd]);
$connA->prepare("INSERT INTO cos_finance_budget_reservations (organization_id,id,envelope_id,source_domain,source_operation_key,amount_minor,state,actor_id,action_id,reason) VALUES (?,?,?,'marketing',?,5000,'RESERVED','301',?,'Settlement fixture')")
    ->execute([$settleOrg,$settleHold,$settleEnvelope,'mkt-paid:'.$settlePlan.':activate',bin2hex(random_bytes(16))]);
$connA->prepare("INSERT INTO cos_finance_paid_exposure_claims (organization_id,reservation_id,envelope_id,source_ref,action_id,actor_id,request_hash,plan_hash,amount_minor,currency) VALUES (?,?,?,?,?,'301',?,?,5000,'USD')")
    ->execute([$settleOrg,$settleHold,$settleEnvelope,$settlePlan,$activateAction,str_repeat('c',64),$planHash]);
$claim=['organization_id'=>$settleOrg,...$repoA->exposure($settleOrg,$settleHold)];
$planFacts=['organization_id'=>$settleOrg,'id'=>$settlePlan,'plan_hash'=>$planHash,'currency'=>'USD','lifetime_minor'=>5000,
    'account_timezone'=>'UTC','account_id'=>'mads-'.str_repeat('1',24),'period_start'=>$periodStart,'period_end'=>$periodEnd];
$terminal=['organization_id'=>$settleOrg,'plan_id'=>$settlePlan,'activation_action_id'=>$activateAction,'state'=>'PAUSED_CONFIRMED',
    'provider_campaign_id'=>'123456','pause_action_id'=>bin2hex(random_bytes(16)),'unresolved_operations'=>[]];
$observation=['organization_id'=>$settleOrg,'ad_account_ref'=>$planFacts['account_id'],'external_id'=>'123456','grain'=>'campaign',
    'window_start'=>$periodStart,'window_end'=>$periodEnd,'currency'=>'USD','timezone'=>'UTC','source_status'=>'REVISED',
    'source_snapshot_ref'=>'mis-'.str_repeat('2',24),'source_hash'=>str_repeat('d',64),'source_revision'=>2,
    'attribution_hash'=>str_repeat('e',64),'reported_spend_decimal'=>'12.500000'];
$document=[...$observation,'provider'=>'meta','document_state'=>'INDEPENDENTLY_REVIEWED_FINAL','allocation_scope'=>'EXACT_CAMPAIGN_NET_MEDIA_SPEND',
    'plan_hash'=>$planHash,'pause_action_id'=>$terminal['pause_action_id'],'document_sha256'=>str_repeat('f',64),
    'document_ref'=>'fixture-document','provider_document_id'=>'fixture-invoice','review_ref'=>'fixture-review','reviewer_id'=>'302','settlement_minor'=>1250];
$facts=['plan'=>$planFacts,'terminal'=>$terminal,'observation'=>$observation,'document'=>$document];
$policy=new \Domains\Finance\Domain\FinancePaidSettlementPolicy();
$hash=$policy->prepare($settleOrg,$claim,$planFacts,$terminal,$observation,$document)['candidate_hash'];
$settlerA=new \Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinancePaidSettlementRepository($connA,$policy);
$settlerB=new \Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinancePaidSettlementRepository($connB,$policy);
foreach([
    fn()=>$settlerB->settle($settleOrg,$settleHold,'303',$settleAction,'settlement-trace',3,$hash,$facts),
    fn()=>$settlerB->settle($settleOrg,$settleHold,'303',$settleAction,'settlement-trace',4,str_repeat('0',64),$facts),
    fn()=>$settlerB->settle('foreign-'.$settleOrg,$settleHold,'303',$settleAction,'settlement-trace',4,$hash,$facts),
    fn()=>$settlerB->settle($settleOrg,$settleHold,'303',$settleAction,'settlement-trace',4,$hash,[...$facts,'document'=>[...$document,'settlement_minor'=>1251]])
] as $unsafe){$denied=false;try{$unsafe();}catch(DomainException){$denied=true;}$check($denied,'Unsafe settlement accepted.');}
$connA->beginTransaction();
try{
    $q=$connA->prepare('SELECT id FROM cos_finance_budget_envelopes WHERE organization_id=? AND id=? FOR UPDATE');$q->execute([$settleOrg,$settleEnvelope]);
    $timeout=false;try{$settlerB->settle($settleOrg,$settleHold,'303',$settleAction,'settlement-trace',4,$hash,$facts);}catch(PDOException){$timeout=true;}
    $check($timeout&&!$connB->inTransaction()&&$settlerA->receipt($settleOrg,$settleHold)===null,'Contended settlement left a receipt.');
}finally{$connA->commit();}
$receipt=$settlerB->settle($settleOrg,$settleHold,'303',$settleAction,'settlement-trace',4,$hash,$facts);
$state=$repoA->envelope($settleOrg,$settleEnvelope);
$check($receipt['fresh']&&$receipt['committed_minor']===1250&&$receipt['released_minor']===3750
    &&$state['reserved_minor']===0&&$state['committed_minor']===1250&&$state['available_minor']===3750&&$state['revision']===5
    &&$repoA->reservations($settleOrg,$settleEnvelope)[0]['state']==='SETTLED','Atomic settlement balances incorrect.');
$check(!$settlerA->settle($settleOrg,$settleHold,'303',$settleAction,'settlement-trace',4,$hash,$facts)['fresh'],'Settlement replay not idempotent.');
$denied=false;try{$settlerA->settle($settleOrg,$settleHold,'303',bin2hex(random_bytes(16)),'settlement-trace',5,$hash,$facts);}catch(DomainException){$denied=true;}
$check($denied&&$repoB->envelope($settleOrg,$settleEnvelope)['committed_minor']===1250,'Second Action committed same exposure.');
$q=$connA->prepare("SELECT COUNT(*) FROM cos_finance_budget_events WHERE organization_id=? AND event_type IN ('PAID_SPEND_COMMITTED','PAID_RESERVE_RELEASED')");
$q->execute([$settleOrg]);$check((int)$q->fetchColumn()===2,'Settlement events duplicated.');
echo "Finance M3 atomic settlement, contention, stale revision, tenant fencing and idempotency PASS\n";
