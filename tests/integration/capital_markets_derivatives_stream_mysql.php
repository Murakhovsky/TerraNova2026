<?php
declare(strict_types=1);
if(getenv('COS_CM_DERIVATIVES_TEST')!=='1')
    throw new RuntimeException('Explicit derivatives MySQL verification flag required.');
require getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/vendor/autoload.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\{
    MysqlDerivativeLifecycleRegistry,MysqlDerivativeStreamTrust
};
$url=(string)getenv('DATABASE_URL');$parts=parse_url($url);
if(!is_array($parts)||($parts['scheme']??'')!=='mysql')throw new RuntimeException('MySQL DSN required.');
$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $parts['host']??'mysql',(int)($parts['port']??3306),ltrim((string)($parts['path']??''),'/')),
    rawurldecode((string)($parts['user']??'')),
    rawurldecode((string)($parts['pass']??'')),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES=>false]);
$org='ci-derivatives-'.bin2hex(random_bytes(5));
$other=$org.'-foreign';$source='bybit-ci';$instrument='BTCUSDT-CI';
$assert=static function(bool $yes,string $why):void{if(!$yes)throw new RuntimeException($why);};
$lifecycle=new MysqlDerivativeLifecycleRegistry($pdo);
$trust=new MysqlDerivativeStreamTrust($pdo,$lifecycle);
$meta=['status'=>'Trading','contract_type'=>'LinearPerpetual',
    'funding_interval_seconds'=>28800,'contract_multiplier'=>'1'];
try{
    $first=$lifecycle->observe($org,$source,$instrument,$meta);
    $assert($first['changed']&&$first['tradable']&&$first['revision']===1,
        'Provider Trading lifecycle first observation did not persist.');
    $lifecycle->assertTrading($org,$source,$instrument);
    $same=$lifecycle->observe($org,$source,$instrument,$meta);
    $assert(!$same['changed']&&$same['revision']===1,
        'Replayed provider status incorrectly created a transition.');
    $foreignFails=false;
    try{$lifecycle->assertTrading($other,$source,$instrument);}
    catch(DomainException){$foreignFails=true;}
    $assert($foreignFails,'Foreign tenant inherited derivative Trading status.');
    $insert=$pdo->prepare(
        "INSERT INTO tn_capital_market_derivative_stream_trust
         (organization_id,source_id,instrument_id,data_type,shard_key,
          fencing_token,session_epoch,status,last_received_at,quality_score)
         VALUES (:org,:src,:instrument,'MARK_PRICE','ci-shard',7,1,
           'TRUSTED',DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 180 SECOND),99)");
    $insert->execute(['org'=>$org,'src'=>$source,'instrument'=>$instrument]);
    $assert($trust->sweep($org,$source,120)===1,
        'Stale derivative capability was not independently expired.');
    $rows=$trust->status($org,$source);
    $assert(count($rows)===1&&$rows[0]['status']==='STALE'
        &&(int)$rows[0]['quality_score']===0,
        'Derivative trust stale state did not quarantine quality.');
    $pdo->prepare(
        "UPDATE tn_capital_market_derivative_stream_trust
         SET status='TRUSTED',quality_score=99
         WHERE organization_id=?")->execute([$org]);
    $changed=$lifecycle->observe($org,$source,$instrument,
        ['status'=>'Delivering','contract_type'=>'LinearPerpetual']);
    $assert($changed['changed']&&!$changed['tradable']&&$changed['revision']===2,
        'Provider contract delivery did not revoke tradability.');
    $fail=false;
    try{$lifecycle->assertTrading($org,$source,$instrument);}
    catch(DomainException){$fail=true;}
    $assert($fail&&$trust->status($org,$source)[0]['status']==='UNTRUSTED',
        'Non-trading contract retained stream trust.');
    $bad=false;
    try{$lifecycle->observe($org,$source,$instrument,
        ['status'=>'Trading','contract_type'=>'InversePerpetual']);}
    catch(DomainException){$bad=true;}
    $assert($bad,'Inverse contract family was silently accepted as Bybit Linear.');
    $reopened=$lifecycle->observe($org,$source,$instrument,$meta);
    $assert($reopened['revision']===3&&$reopened['changed'],
        'Trading restoration lost its durable transition.');
    $q=$pdo->prepare(
        "SELECT new_status FROM tn_capital_market_derivative_contract_transitions
         WHERE organization_id=? ORDER BY id");
    $q->execute([$org]);$statuses=$q->fetchAll(PDO::FETCH_COLUMN);
    $assert($statuses===['Trading','Delivering','Trading'],
        'Contract transitions not faithfully audited.');
    $pdo->prepare(
        "UPDATE tn_capital_market_derivative_contract_status
         SET observed_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE)
         WHERE organization_id=?")->execute([$org]);
    $stale=false;
    try{$lifecycle->assertTrading($org,$source,$instrument);}
    catch(DomainException){$stale=true;}
    $assert($stale,'Expired REST lifecycle identity allowed live streaming.');
    $assert($trust->status($other,$source)===[],
        'Per-capability derivative trust leaks between tenants.');
    echo "Derivative contract transitions, stale gates, tenant isolation and trust quarantine MySQL passed.\n";
}finally{
    foreach(['tn_capital_market_derivative_stream_trust',
        'tn_capital_market_derivative_contract_transitions',
        'tn_capital_market_derivative_contract_status',
        'tn_capital_market_stream_worker_leases'] as $table){
        $pdo->prepare("DELETE FROM $table WHERE organization_id IN (:a,:b)")
            ->execute(['a'=>$org,'b'=>$other]);
    }
}
