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
    // A real MySQL canonical event, ACTIVE subscription, quality row and
    // currently fenced source lease are all required for capability trust.
    $pdo->prepare(
        "INSERT INTO tn_capital_market_data_sources
         (organization_id,source_id,venue_id,adapter_type,enabled,priority,
          roles_json,credentials_reference,rate_limit_policy_json,reconnect_policy_json,
          health_policy_json,license_profile,metadata_json)
         VALUES (:org,:source,NULL,'bybit.linear-perpetual.v5',1,10,
                 '[\"trading_source\"]',NULL,'{}','{}','{}','PUBLIC_FEED','{}')")
        ->execute(['org'=>$org,'source'=>$source]);
    $pdo->prepare(
        "INSERT INTO tn_capital_market_instruments
         (organization_id,instrument_id,symbol,canonical_symbol,name,family,status,
          metadata_json,created_at,updated_at)
         VALUES (:org,:id,'BTCUSDT','BTCUSDT-CI','CI perpetual','perpetual',
                 'ACTIVE','{}',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
        ->execute(['org'=>$org,'id'=>$instrument]);
    $pdo->prepare(
        "INSERT INTO tn_capital_market_subscriptions
         (organization_id,subscription_id,source_id,instrument_id,target_fingerprint,
          data_type,status,subscribed_at)
         VALUES (:org,:id,:src,:instrument,:fp,'MARK_PRICE','ACTIVE',UTC_TIMESTAMP(6))")
        ->execute(['org'=>$org,'id'=>'ci-'.bin2hex(random_bytes(6)),
            'src'=>$source,'instrument'=>$instrument,
            'fp'=>hash('sha256',$org.$instrument.'MARK_PRICE')]);
    $pdo->prepare(
        "INSERT INTO tn_capital_market_stream_worker_leases
         (organization_id,source_id,shard_key,owner_id,fencing_token,expires_at,updated_at)
         VALUES (:org,:src,'ci-derivative-shard','ci-worker',8,
                 DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE),UTC_TIMESTAMP(6))")
        ->execute(['org'=>$org,'src'=>$source]);
    $observation=new class implements \Domains\CapitalMarkets\Domain\MarketData\MarketObservation{
        public function eventType():\Domains\CapitalMarkets\Domain\MarketData\MarketEventType{
            return \Domains\CapitalMarkets\Domain\MarketData\MarketEventType::MarkPrice;
        }
        public function toArray():array{return ['value'=>'100.0123'];}
    };
    $makeResult=static function(string $id)use($source,$instrument,$observation){
        $stamp=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $event=new \Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent(
            $id,\Domains\CapitalMarkets\Domain\MarketData\MarketSourceId::fromString($source),
            null,\Domains\CapitalMarkets\Domain\Instrument\InstrumentId::fromString($instrument),
            new \Domains\CapitalMarkets\Domain\MarketData\MarketTimestamps($stamp,$stamp,$stamp),
            '100',$observation,[],1,
            \Domains\CapitalMarkets\Domain\MarketData\MarketDataMode::Live,
            \Domains\CapitalMarkets\Domain\MarketData\MarketStatus::Open
        );
        $quality=new \Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment(
            \Domains\CapitalMarkets\Domain\MarketData\MarketTrustStatus::Trusted,99,[],1,1,1
        );
        return new \Domains\CapitalMarkets\Application\DTO\MarketDataIngestionResult(
            'ACCEPTED',true,true,true,$event,$quality,null
        );
    };
    $eventId='ci-derivative-'.bin2hex(random_bytes(10));
    $failed=false;
    try{$trust->record($org,$source,'ci-derivative-shard','ci-worker',8,1,$makeResult($eventId));}
    catch(DomainException){$failed=true;}
    $assert($failed,'Derivative stream accepted an unpersisted synthetic canonical event.');
    $stamp=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    $pdo->prepare(
        "INSERT INTO tn_capital_market_canonical_events
         (organization_id,canonical_event_id,source_id,venue_id,instrument_id,
          event_type,source_timestamp,received_timestamp,processed_timestamp,
          sequence_value,payload_json,quality_flags_json,schema_version,fingerprint,
          data_mode,market_status)
         VALUES (:org,:event,:src,NULL,:instrument,'MARK_PRICE',:stamp,:received,
                 :processed,'100','{}','[]',1,:fingerprint,'LIVE','OPEN')")
        ->execute(['org'=>$org,'event'=>$eventId,'src'=>$source,'instrument'=>$instrument,
            'stamp'=>$stamp,'received'=>$stamp,'processed'=>$stamp,
            'fingerprint'=>hash('sha256',$eventId)]);
    $pdo->prepare(
        "INSERT INTO tn_capital_market_quality_metrics
         (organization_id,canonical_event_id,source_id,venue_id,instrument_id,
          trust_status,quality_score,flags_json,ingestion_latency_ms,
          processing_latency_ms,event_age_ms,recorded_at)
         VALUES (:org,:event,:src,NULL,:instrument,'TRUSTED',99,'[]',1,1,1,:stamp)")
        ->execute(['org'=>$org,'event'=>$eventId,'src'=>$source,'instrument'=>$instrument,
            'stamp'=>$stamp]);
    $assert($trust->record($org,$source,'ci-derivative-shard','ci-worker',8,1,
        $makeResult($eventId)),'Fenced canonical LIVE derivative with quality proof was rejected.');
    $good=$trust->status($org,$source);
    $assert(count($good)===1&&$good[0]['status']==='TRUSTED'
        &&(int)$good[0]['accepted_count']===1,
        'Per-derivative trust did not persist after fenced canonical data.');
    $failed=false;
    try{$trust->record($org,$source,'ci-derivative-shard','stolen-worker',8,1,$makeResult($eventId));}
    catch(DomainException){$failed=true;}
    $assert($failed,'Foreign lease owner replayed derivative evidence.');
    $insert=$pdo->prepare(
        "INSERT INTO tn_capital_market_derivative_stream_trust
         (organization_id,source_id,instrument_id,data_type,shard_key,
          fencing_token,session_epoch,status,last_received_at,quality_score)
         VALUES (:org,:src,:instrument,'MARK_PRICE','ci-shard',7,1,
           'TRUSTED',DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 180 SECOND),99)
         ON DUPLICATE KEY UPDATE status='TRUSTED',
           last_received_at=VALUES(last_received_at),quality_score=99");
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
    foreach(['tn_capital_market_quality_metrics','tn_capital_market_canonical_events',
        'tn_capital_market_subscriptions','tn_capital_market_instruments',
        'tn_capital_market_data_sources','tn_capital_market_derivative_stream_trust',
        'tn_capital_market_derivative_contract_transitions',
        'tn_capital_market_derivative_contract_status',
        'tn_capital_market_stream_worker_leases'] as $table){
        $pdo->prepare("DELETE FROM $table WHERE organization_id IN (:a,:b)")
            ->execute(['a'=>$org,'b'=>$other]);
    }
}
