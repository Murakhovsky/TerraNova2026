<?php
declare(strict_types=1);

/**
 * Real MySQL proof of tenant isolation, two-person entitlement review and
 * canonical+quality+lease evidence admission. Records are CI-only fixtures,
 * deliberately never counted as a real production soak.
 */
if(getenv('COS_CM_POOL_PRODUCTION_TEST')!=='1'){
    throw new RuntimeException('Explicit isolated production gate MySQL test flag required.');
}
require getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/vendor/autoload.php';
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Service\{
    InstrumentPoolExternalEvidence,InstrumentPoolProductionEvidenceReader,
    InstrumentPoolProductionSoakPolicy
};
$parts=parse_url((string)getenv('DATABASE_URL'));
if(!is_array($parts)||($parts['scheme']??null)!=='mysql')throw new RuntimeException('MySQL fixture DSN required.');
$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $parts['host']??'mysql',(int)($parts['port']??3306),ltrim((string)($parts['path']??''),'/')),
    rawurldecode((string)($parts['user']??'')),
    rawurldecode((string)($parts['pass']??'')),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
$assert=static function(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);};
$access=new class implements CapitalMarketsAccessControlInterface{
    public function hasCapability(string $organizationId,int $userId,string $capability):bool{
        return in_array($userId,[701,702],true)
            &&in_array($capability,['capital_markets.instrument_pool.propose',
                'capital_markets.instrument_pool.approve'],true);
    }
};
$proof=new InstrumentPoolExternalEvidence($pdo,$access);
$reader=new InstrumentPoolProductionEvidenceReader($pdo,$proof,new InstrumentPoolProductionSoakPolicy());
$org='ci-prod-'.bin2hex(random_bytes(6));
$foreign=$org.'-foreign';
$source='source:prod-gate';
$token='instrument:ci:token';
$l2='instrument:ci:l2';
try{
    $url='https://example.org/audit/source-evidence.pdf';
    $e=$proof->propose($org,701,'SOURCE_ENTITLEMENT',$source,
        $url,str_repeat('a',64),gmdate('Y-m-d H:i:s',time()+86400));
    $assert($proof->approved($org)===[],'PENDING entitlement counted as approved.');
    $rejected=false;
    try{$proof->review($org,701,$e,true);}catch(DomainException){$rejected=true;}
    $assert($rejected,'Evidence self approval was permitted.');
    $proof->review($org,702,$e,true);
    $assert(isset($proof->approved($org)['SOURCE_ENTITLEMENT|'.$source]),
        'Independent source review was lost.');
    $assert($proof->approved($foreign)===[],'Entitlement leaked to another tenant.');
    $rejected=false;
    try{$proof->review($org,702,$e,true);}catch(DomainException){$rejected=true;}
    $assert($rejected,'Attestation replay was permitted.');
    $rejected=false;
    try{$proof->propose($org,701,'SOURCE_ENTITLEMENT',$source,
        'https://example.org/document?access_token=secret',str_repeat('b',64),
        gmdate('Y-m-d H:i:s',time()+86400));}catch(DomainException){$rejected=true;}
    $assert($rejected,'Secret-bearing evidence URLs were allowed.');
    $makeSource=$pdo->prepare(
        "INSERT INTO tn_capital_market_data_sources
        (organization_id,source_id,venue_id,adapter_type,enabled,priority,
         roles_json,credentials_reference,rate_limit_policy_json,reconnect_policy_json,
         health_policy_json,license_profile,metadata_json)
         VALUES (:org,:source,NULL,'ci.mock',1,10,
                 '[\"trading_source\"]',NULL,'{}','{}','{}','PUBLIC_FEED',
                 '{\"data_mode\":\"LIVE\"}')");
    $makeSource->execute(['org'=>$org,'source'=>$source]);
    $makeInstrument=$pdo->prepare(
        "INSERT INTO tn_capital_market_instruments
        (organization_id,instrument_id,symbol,canonical_symbol,name,family,status,
         metadata_json,created_at,updated_at)
         VALUES (:org,:id,:symbol,:canonical,:name,:family,'ACTIVE',
                 '{\"origin\":\"BINANCE_BSTOCKS_SEED\"}',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
    $makeInstrument->execute(['org'=>$org,'id'=>$token,'symbol'=>'CIPROD1',
        'canonical'=>$org.'-token','name'=>'CI Token',
        'family'=>'tokenized_security']);
    $makeInstrument->execute(['org'=>$org,'id'=>$l2,'symbol'=>'CIPROD2',
        'canonical'=>$org.'-l2','name'=>'CI L2','family'=>'spot_market']);
    // L2 is spot; mark non-tokenized origin to isolate issuer requirement to the token.
    $pdo->prepare("UPDATE tn_capital_market_instruments
        SET metadata_json='{}' WHERE organization_id=? AND instrument_id=?")
        ->execute([$org,$l2]);
    $lease=$pdo->prepare(
        "INSERT INTO tn_capital_market_stream_worker_leases
         (organization_id,source_id,shard_key,owner_id,fencing_token,expires_at,updated_at)
         VALUES (:org,:src,'ci-shard','ci-worker',7,
          DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE),UTC_TIMESTAMP(6))");
    $lease->execute(['org'=>$org,'src'=>$source]);
    $insertEvent=$pdo->prepare(
        "INSERT INTO tn_capital_market_canonical_events
         (organization_id,canonical_event_id,source_id,venue_id,instrument_id,
          event_type,source_timestamp,received_timestamp,processed_timestamp,
          sequence_value,payload_json,quality_flags_json,schema_version,fingerprint,
          data_mode,market_status)
         VALUES (:org,:event,:src,NULL,:instrument,:kind,
          :source_time,:received_time,:processed_time,:seq,'{}','[]',1,:fp,'LIVE','OPEN')");
    $insertQuality=$pdo->prepare(
        "INSERT INTO tn_capital_market_quality_metrics
         (organization_id,canonical_event_id,source_id,venue_id,instrument_id,
          trust_status,quality_score,flags_json,
          ingestion_latency_ms,processing_latency_ms,event_age_ms,recorded_at)
         VALUES (:org,:event,:src,NULL,:instrument,'TRUSTED',99,'[]',1,1,1,:timestamp)");
    $firstEvent=null;
    for($hour=0;$hour<24;$hour++){
        foreach([[$token,'BBO'],[$l2,'ORDER_BOOK_SNAPSHOT']] as [$instrument,$kind]){
            $timestamp=gmdate('Y-m-d H:i:s',time()-($hour*3600)-20);
            $event='ci-'.bin2hex(random_bytes(12));
            $data=['org'=>$org,'event'=>$event,'src'=>$source,'instrument'=>$instrument,
                'kind'=>$kind,'timestamp'=>$timestamp,'seq'=>(string)(1000-$hour),
                'fp'=>hash('sha256',$event),
                'source_time'=>$timestamp,'received_time'=>$timestamp,'processed_time'=>$timestamp];
            $insertEvent->execute(array_intersect_key($data,array_flip(
                ['org','event','src','instrument','kind','seq','fp',
                 'source_time','received_time','processed_time'])));
            $insertQuality->execute(array_intersect_key($data,array_flip(
                ['org','event','src','instrument','timestamp'])));
            if($firstEvent===null)$firstEvent=$event;
        }
    }
    $start=gmdate('Y-m-d H:i:s',time()-86402);
    $result=$reader->report($org,$start,[$source]);
    $assert($result['verified']['BBO']===0&&$result['verified']['L2']===1
        &&$result['accepted']===false,
        'Tokenized instrument without issuer review passed.');
    $issuer=$proof->propose($org,701,'ISSUER_IDENTITY',$token,
        'https://example.org/audit/issuer.pdf',str_repeat('b',64),
        gmdate('Y-m-d H:i:s',time()+86400));
    $proof->review($org,702,$issuer,true);
    $result=$reader->report($org,$start,[$source]);
    $assert($result['verified']['BBO']===1&&$result['verified']['L2']===1
        &&$result['accepted']===false
        &&$result['gates']['BBO_250']===false
        &&$result['gates']['L2_50']===false,
        'Fixture passed 250 BBO/50 L2 production acceptance or trusted symbols were not counted.');
    $assert($reader->canaryHealthy($org,[$source]),'Trusted live MySQL canary rejected.');
    $pdo->prepare("UPDATE tn_capital_market_quality_metrics
      SET trust_status='UNTRUSTED' WHERE organization_id=? AND canonical_event_id=?")
      ->execute([$org,$firstEvent]);
    $result=$reader->report($org,$start,[$source]);
    $assert($result['verified']['BBO']===0&&!$result['accepted'],
        'Persistence quality corruption was not caught.');
    $assert($reader->report($foreign,$start,[$source])['verified']['L2']===0,
        'Other tenant stole real canonical acceptance evidence.');
    echo "Production attestation, canary, LIVE 24h MySQL quality, lease, issuer and tenant isolation passed. Fixtures are NOT real soak.\n";
}finally{
    foreach(['tn_capital_market_quality_metrics','tn_capital_market_canonical_events',
        'tn_capital_market_stream_worker_leases',
        'tn_capital_market_pool_external_evidence',
        'tn_capital_market_instruments','tn_capital_market_data_sources'] as $table){
        $pdo->prepare("DELETE FROM $table WHERE organization_id=:org")->execute(['org'=>$org]);
    }
}
