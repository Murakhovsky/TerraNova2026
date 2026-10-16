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
    $replacement=$proof->propose($org,701,'SOURCE_ENTITLEMENT',$source,
        $url,str_repeat('c',64),gmdate('Y-m-d H:i:s',time()+86400));
    $assert(!isset($proof->approved($org)['SOURCE_ENTITLEMENT|'.$source]),
        'Older approved entitlement bypassed newer PENDING document.');
    $proof->review($org,702,$replacement,false);
    $assert(!isset($proof->approved($org)['SOURCE_ENTITLEMENT|'.$source]),
        'Older approved entitlement bypassed rejected replacement.');
    $replacement=$proof->propose($org,701,'SOURCE_ENTITLEMENT',$source,
        $url,str_repeat('d',64),gmdate('Y-m-d H:i:s',time()+86400));
    $proof->review($org,702,$replacement,true);
    $assert(isset($proof->approved($org)['SOURCE_ENTITLEMENT|'.$source]),
        'Independent replacement approval did not become effective.');
    $proof->revoke($org,702,$replacement,'Provider approval cancelled by operator.');
    $assert(!isset($proof->approved($org)['SOURCE_ENTITLEMENT|'.$source]),
        'Revoked source entitlement still admitted in production.');
    $replacement=$proof->propose($org,701,'SOURCE_ENTITLEMENT',$source,
        $url,str_repeat('e',64),gmdate('Y-m-d H:i:s',time()+86400));
    $proof->review($org,702,$replacement,true);
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
    $pdo->prepare(
        "INSERT INTO tn_capital_market_source_health
         (organization_id,source_id,connection_state,last_event_at,last_heartbeat_at,
          failure_count,queue_lag,clock_reliable,rate_limit_state)
         VALUES (:org,:src,'ACTIVE',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),0,0,1,'NORMAL')")
        ->execute(['org'=>$org,'src'=>$source]);
    $subscribe=$pdo->prepare(
        "INSERT INTO tn_capital_market_subscriptions
         (organization_id,subscription_id,source_id,instrument_id,target_fingerprint,
          data_type,status,subscribed_at)
         VALUES (:org,:id,:src,:instrument,:fingerprint,:type,'ACTIVE',UTC_TIMESTAMP(6))");
    foreach([[$token,'BBO'],[$l2,'ORDER_BOOK_SNAPSHOT']] as [$id,$type]){
        $subscribe->execute(['org'=>$org,'id'=>'ci-'.bin2hex(random_bytes(10)),
            'src'=>$source,'instrument'=>$id,'fingerprint'=>hash('sha256',$org.$id.$type),
            'type'=>$type]);
    }
    $assignment=$pdo->prepare(
        "INSERT INTO tn_capital_market_pool_stream_assignments
         (organization_id,source_id,instrument_id,kind,shard_key,assigned_by)
         VALUES (:org,:src,:instrument,:kind,:shard,701)");
    foreach([[$token,'BBO'],[$l2,'L2']] as [$instrument,$kind]){
        $assignment->execute(['org'=>$org,'src'=>$source,'instrument'=>$instrument,
            'kind'=>$kind,'shard'=>'ci-shard']);
    }
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
    for($slot=0;$slot<288;$slot++){
        foreach([[$token,'BBO'],[$l2,'ORDER_BOOK_SNAPSHOT']] as [$instrument,$kind]){
            $timestamp=gmdate('Y-m-d H:i:s',time()-($slot*300)-20);
            $event='ci-'.bin2hex(random_bytes(12));
            $data=['org'=>$org,'event'=>$event,'src'=>$source,'instrument'=>$instrument,
                'kind'=>$kind,'timestamp'=>$timestamp,'seq'=>(string)(2000-$slot),
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
    $ledger=$pdo->prepare(
        "INSERT INTO tn_capital_market_instrument_stream_trust
         (organization_id,source_id,instrument_id,channel,shard_key,
          fencing_token,status,last_sequence,last_event_at,last_quality_score,recovery_required)
         VALUES (:org,:src,:instrument,:channel,'ci-shard',7,
                 'TRUSTED','150',UTC_TIMESTAMP(6),99,0)");
    foreach([[$token,'BBO'],[$l2,'L2']] as [$instrument,$channel]){
        $ledger->execute(['org'=>$org,'src'=>$source,
            'instrument'=>$instrument,'channel'=>$channel]);
    }
    $receiptInsert=$pdo->prepare(
        "INSERT INTO tn_capital_market_provider_stream_receipts
         (organization_id,source_id,instrument_id,channel,shard_key,
          fencing_token,session_epoch,provider,status,ack_at,last_live_quote_at,
          quote_count,last_quality_score)
         VALUES (:org,:source,:instrument,:channel,'ci-shard',7,1,
                 'BINANCE_SPOT','LIVE_QUOTE',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),288,99)");
    foreach([[$token,'BBO'],[$l2,'L2']] as [$instrument,$channel]){
        $receiptInsert->execute(['org'=>$org,'source'=>$source,
            'instrument'=>$instrument,'channel'=>$channel]);
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
    $assert(!$reader->soakCheckpoints($org,$start)['accepted'],
        'Absent live watchdog checkpoints passed production acceptance.');
    // Production PDO reads DATETIME(6), not a timestamp without fractional seconds.
    $c=$reader->recordSoakCheckpoint($org,$start.'.000000',[$source]);
    $assert($c['healthy']===true
        &&$c['checkpoint']['observations']===1,
        'Live watchdog checkpoint was not persisted.');
    $pdo->prepare(
        "UPDATE tn_capital_market_pool_soak_checkpoints SET healthy=0
         WHERE organization_id=?")->execute([$org]);
    $reader->recordSoakCheckpoint($org,$start.'.000000',[$source]);
    $assert($reader->soakCheckpoints($org,$start.'.000000')['healthy']===0,
        'Healthy retry resurrected previously failing watchdog bucket.');
    $pdo->prepare("UPDATE tn_capital_market_pool_stream_assignments
                   SET shard_key='absent-worker' WHERE organization_id=? AND instrument_id=?")
        ->execute([$org,$l2]);
    $wrongShard=$reader->report($org,$start,[$source]);
    $assert($wrongShard['verified']['L2']===0,
        'Unrelated active source lease incorrectly certified L2 shard.');
    $pdo->prepare("UPDATE tn_capital_market_pool_stream_assignments
                   SET shard_key='ci-shard' WHERE organization_id=? AND instrument_id=?")
        ->execute([$org,$l2]);
    $pdo->prepare("UPDATE tn_capital_market_subscriptions SET status='DISABLED'
                   WHERE organization_id=? AND instrument_id=?")
        ->execute([$org,$l2]);
    $noSub=$reader->report($org,$start,[$source]);
    $assert($noSub['verified']['L2']===0&&!$noSub['accepted'],
        'Live event with disabled canonical subscription was accepted.');
    $pdo->prepare("UPDATE tn_capital_market_subscriptions SET status='ACTIVE'
                   WHERE organization_id=? AND instrument_id=?")
        ->execute([$org,$l2]);
    $pdo->prepare("UPDATE tn_capital_market_source_health SET clock_reliable=0
                   WHERE organization_id=? AND source_id=?")
        ->execute([$org,$source]);
    $badClock=$reader->report($org,$start,[$source]);
    $assert($badClock['verified']['L2']===0&&!$reader->canaryHealthy($org,[$source]),
        'Unreliable source clock was accepted.');
    $pdo->prepare("UPDATE tn_capital_market_source_health SET clock_reliable=1
                   WHERE organization_id=? AND source_id=?")
        ->execute([$org,$source]);
    $pdo->prepare(
        "INSERT INTO tn_capital_market_data_gaps
         (organization_id,gap_id,source_id,instrument_id,data_type,gap_from,gap_to,reason,status)
         VALUES (:org,:gap,:src,:instrument,'ORDER_BOOK_SNAPSHOT',
          DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 60 MINUTE),
          DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 30 MINUTE),'CI gap','OPEN')")
        ->execute(['org'=>$org,'gap'=>'ci-gap-'.bin2hex(random_bytes(4)),'src'=>$source,'instrument'=>$l2]);
    $gapReport=$reader->report($org,$start,[$source]);
    $assert($gapReport['verified']['L2']===0,'Unresolved source data gap was accepted.');
    $pdo->prepare("DELETE FROM tn_capital_market_data_gaps
                   WHERE organization_id=?")->execute([$org]);
    $pdo->prepare("UPDATE tn_capital_market_quality_metrics
      SET trust_status='UNTRUSTED' WHERE organization_id=? AND canonical_event_id=?")
      ->execute([$org,$firstEvent]);
    $result=$reader->report($org,$start,[$source]);
    $assert($result['verified']['BBO']===0&&!$result['accepted'],
        'Persistence quality corruption was not caught.');
    $assert($reader->report($foreign,$start,[$source])['verified']['L2']===0,
        'Other tenant stole real canonical acceptance evidence.');
    // P0-5: only an actual protocol ACK from a fenced worker is accepted.
    $provider=new \Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlStreamingProviderVerification($pdo);
    $provider->acknowledged($org,$source,'ci-shard','ci-worker',7,2,
        'BINANCE_SPOT',$token);
    $check=$pdo->prepare(
        "SELECT status,quote_count FROM tn_capital_market_provider_stream_receipts
         WHERE organization_id=? AND source_id=? AND instrument_id=? AND channel='BBO'");
    $check->execute([$org,$source,$token]);
    $proofRow=$check->fetch(PDO::FETCH_ASSOC);
    $assert($proofRow['status']==='ACKED'&&(int)$proofRow['quote_count']===0,
        'Old LIVE evidence was not revoked by new session ACK.');
    $staleOwnerRejected=false;
    try{$provider->acknowledged($org,$source,'ci-shard','stolen-worker',7,3,
        'BINANCE_SPOT',$token);}
    catch(DomainException){$staleOwnerRejected=true;}
    $assert($staleOwnerRejected,'Forged worker recorded a provider ACK.');
    $observation=new class implements \Domains\CapitalMarkets\Domain\MarketData\MarketObservation{
        public function eventType():\Domains\CapitalMarkets\Domain\MarketData\MarketEventType{
            return \Domains\CapitalMarkets\Domain\MarketData\MarketEventType::Bbo;
        }
        public function toArray():array{return ['bid_price'=>'10.0','ask_price'=>'10.1'];}
    };
    $qualityAssessment=new \Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment(
        \Domains\CapitalMarkets\Domain\MarketData\MarketTrustStatus::Trusted,99,[],1,1,1
    );
    $makeQuote=static function(string $eventId)use($source,$token,$observation,$qualityAssessment){
        $stamp=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $event=new \Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent(
            $eventId,
            \Domains\CapitalMarkets\Domain\MarketData\MarketSourceId::fromString($source),
            null,\Domains\CapitalMarkets\Domain\Instrument\InstrumentId::fromString($token),
            new \Domains\CapitalMarkets\Domain\MarketData\MarketTimestamps($stamp,$stamp,$stamp),
            '300',$observation,[],1,
            \Domains\CapitalMarkets\Domain\MarketData\MarketDataMode::Live,
            \Domains\CapitalMarkets\Domain\MarketData\MarketStatus::Open
        );
        return new \Domains\CapitalMarkets\Application\DTO\MarketDataIngestionResult(
            'ACCEPTED',true,true,true,$event,$qualityAssessment,null
        );
    };
    $fake=$makeQuote('ci-no-real-canonical-event');
    $rejected=false;
    try{$provider->acceptedQuote($org,$source,'ci-shard','ci-worker',7,2,$fake);}
    catch(DomainException){$rejected=true;}
    $assert($rejected,'Unpersisted synthetic event promoted to genuine provider quote.');
    $persist=static function(string $eventId)use(
        $pdo,$org,$source,$token,$insertEvent,$insertQuality
    ):void{
        $stamp=(new DateTimeImmutable('now',new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s.u');
        $record=['org'=>$org,'event'=>$eventId,'src'=>$source,'instrument'=>$token,
            'kind'=>'BBO','seq'=>'300','fp'=>hash('sha256',$eventId),
            'source_time'=>$stamp,'received_time'=>$stamp,'processed_time'=>$stamp];
        $insertEvent->execute($record);
        $insertQuality->execute(['org'=>$org,'event'=>$eventId,'src'=>$source,
            'instrument'=>$token,'timestamp'=>$stamp]);
    };
    $newEvent='ci-live-prov-'.bin2hex(random_bytes(10));
    $persist($newEvent);
    $provider->acceptedQuote($org,$source,'ci-shard','ci-worker',7,2,$makeQuote($newEvent));
    $check->execute([$org,$source,$token]);$proofRow=$check->fetch(PDO::FETCH_ASSOC);
    $assert($proofRow['status']==='LIVE_QUOTE'&&(int)$proofRow['quote_count']===1,
        'Persisted post-ACK canonical LIVE quality did not create provider receipt.');

    // P0-6: persistence, ACK vs resolution and reopen after recurrence.
    $manager=new \Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlStreamingIncidentManager($pdo);
    $signal=[['source_id'=>$source,'code'=>'STREAM_PROVIDER_LIVE_NOT_VERIFIED',
        'severity'=>'CRITICAL','count'=>1]];
    $detected=$manager->detect($org,$signal);
    $assert($detected['opened']===1,'First source incident was not persisted.');
    $listed=$manager->list($org,$source);
    $incidentId=(int)$listed[0]['id'];
    $assert($manager->list($foreign,$source)===[],
        'Incident IDs leaked outside their tenant.');
    $assert($manager->acknowledge($org,701,$incidentId),
        'Operator acknowledgement did not persist.');
    $manager->detect($org,$signal);
    $listed=$manager->list($org,$source);
    $assert($listed[0]['status']==='ACKNOWLEDGED'
        &&(int)$listed[0]['occurrences']>=2,
        'Repeated incident discarded ACK or occurrence count.');
    $assert($manager->resolve($org,$incidentId,$source,'STREAM_PROVIDER_LIVE_NOT_VERIFIED'),
        'Independent recovery resolution was not persisted.');
    $again=$manager->detect($org,$signal);
    $assert($again['reopened']===1,
        'Unresolved provider fault did not re-open persisted incident.');

    // P0-7: controlled fault injection fences old token and requires new
    // lease + fresh provider ACK + canonical quote; synthetic CI exercise
    // does not stand in for actual production chaos.
    $savePhase=$pdo->prepare(
        "INSERT INTO tn_capital_market_pool_production_control
         (organization_id,phase,source_ids_json,initially_disabled_json,
          operator_id,soak_started_at)
         VALUES (:org,'SOAK',:sources,'[]',701,:since)");
    $savePhase->execute(['org'=>$org,'sources'=>json_encode([$source],JSON_THROW_ON_ERROR),
        'since'=>$start]);
    $failover=new \Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlStreamingFailoverAudit($pdo);
    $assert(!$failover->provenSince($org,$source,$start),
        'Unchallenged source counted as failover-proven.');
    $challenge=$failover->inject($org,$source);
    $assert($challenge['status']==='INJECTED'
        &&(int)$challenge['previous_fencing_token']===7,
        'Controlled failover did not fence the old lease.');
    $rejected=false;
    try{$provider->acknowledged($org,$source,'ci-shard','ci-worker',7,3,
        'BINANCE_SPOT',$token);}
    catch(DomainException){$rejected=true;}
    $assert($rejected,'Superseded worker accepted after failover fencing.');
    $rejected=false;
    try{$failover->verify($org,$source);}catch(DomainException){$rejected=true;}
    $assert($rejected,'Failover accepted without new independently leased worker.');
    $pdo->prepare(
        "UPDATE tn_capital_market_stream_worker_leases
         SET owner_id='ci-new-worker',fencing_token=11,
           expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE)
         WHERE organization_id=? AND source_id=?")->execute([$org,$source]);
    $pdo->prepare(
        "UPDATE tn_capital_market_instrument_stream_trust
         SET status='TRUSTED',recovery_required=0,last_quality_score=99,fencing_token=11
         WHERE organization_id=? AND source_id=? AND instrument_id=? AND channel='BBO'")
        ->execute([$org,$source,$token]);
    $provider->acknowledged($org,$source,'ci-shard','ci-new-worker',11,4,
        'BINANCE_SPOT',$token);
    $rejected=false;
    try{$failover->verify($org,$source);}catch(DomainException){$rejected=true;}
    $assert($rejected,'Fresh lease/ACK alone passed without new LIVE canonical quote.');
    $pdo->prepare("UPDATE tn_capital_market_source_health
         SET connection_state='ACTIVE',clock_reliable=1,queue_lag=0,
             last_event_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
         WHERE organization_id=? AND source_id=?")->execute([$org,$source]);
    $post='ci-post-failover-'.bin2hex(random_bytes(10));
    $persist($post);
    $provider->acceptedQuote($org,$source,'ci-shard','ci-new-worker',11,4,$makeQuote($post));
    $recovery=$failover->verify($org,$source);
    $assert($recovery['status']==='RECOVERED'&&$recovery['after']===11,
        'New fenced worker/provider quote was not accepted as recovery.');
    $assert($failover->provenSince($org,$source,$start),
        'Verified source failover not reflected in production acceptance.');
    $rejected=false;
    try{$failover->verify($org,$source);}catch(DomainException){$rejected=true;}
    $assert($rejected,'Failover evidence replay accepted twice.');
    echo "Production attestation, canary, LIVE 24h MySQL quality, lease, issuer and tenant isolation passed. Fixtures are NOT real soak.\n";
}finally{
    foreach(['tn_capital_market_pool_production_control','tn_capital_market_provider_stream_receipts','tn_capital_market_stream_incidents',
        'tn_capital_market_stream_failover_observations','tn_capital_market_instrument_stream_trust','tn_capital_market_pool_soak_checkpoints',
        'tn_capital_market_pool_stream_assignments','tn_capital_market_data_gaps','tn_capital_market_quality_metrics','tn_capital_market_canonical_events',
        'tn_capital_market_stream_worker_leases',
        'tn_capital_market_pool_external_evidence',
        'tn_capital_market_subscriptions','tn_capital_market_source_health',
        'tn_capital_market_instruments','tn_capital_market_data_sources'] as $table){
        $pdo->prepare("DELETE FROM $table WHERE organization_id=:org")->execute(['org'=>$org]);
    }
}
