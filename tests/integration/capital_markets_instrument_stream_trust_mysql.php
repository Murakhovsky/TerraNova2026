<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\StreamingTrustInvalidator;

if(getenv('COS_CM_INSTRUMENT_TRUST_TEST')!=='1'){
    throw new RuntimeException('Explicit per-instrument trust integration flag required.');
}
require getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/vendor/autoload.php';
$url=getenv('DATABASE_URL');
$parts=is_string($url)?parse_url($url):false;
if(!is_array($parts)||($parts['scheme']??null)!=='mysql'){
    throw new RuntimeException('Per-instrument trust integration requires MySQL DATABASE_URL.');
}
$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $parts['host']??'mysql',(int)($parts['port']??3306),ltrim((string)($parts['path']??''),'/'));
$pdo=new PDO($dsn,rawurldecode((string)($parts['user']??'')),
    rawurldecode((string)($parts['pass']??'')),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
$assert=static function(bool $valid,string $reason):void{
    if(!$valid)throw new RuntimeException($reason);
};
$org='ci-cm-instrument-trust-'.bin2hex(random_bytes(6));
$source='ci-massive-multi-source';
$shard='massive-stocks-multi-nbbo';
$worker='ci-massive-worker';
$token=3;
$instruments=['ci-equity-a','ci-equity-b'];
$venue='ci-equity-venue';
try{
    // Isolated throwaway test IDs: skip parent FK checks only for fixture inserts.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $lease=$pdo->prepare(
        'INSERT INTO tn_capital_market_stream_worker_leases
         (organization_id,source_id,shard_key,owner_id,fencing_token,expires_at,updated_at)
         VALUES (:org,:source,:shard,:worker,:token,
                 DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 120 SECOND),UTC_TIMESTAMP(6))'
    );
    $lease->execute([
        'org'=>$org,'source'=>$source,'shard'=>$shard,'worker'=>$worker,'token'=>$token,
    ]);
    $health=$pdo->prepare(
        "INSERT INTO tn_capital_market_source_health
         (organization_id,source_id,connection_state,failure_count,error_count,last_error)
         VALUES (:org,:source,'ACTIVE',4,2,NULL)"
    );
    $health->execute(['org'=>$org,'source'=>$source]);
    $trading=$pdo->prepare(
        "INSERT INTO tn_capital_market_states
         (organization_id,venue_id,instrument_id,source_id,state_json,trust_status,quality_score,state_version,
          source_timestamp,updated_at,last_sequence,last_event_fingerprint,data_mode)
         VALUES (:org,:venue,:instrument,:source,:json,'TRUSTED',90,1,
          UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),'10',:fingerprint,'LIVE')"
    );
    $reference=$pdo->prepare(
        "INSERT INTO tn_capital_market_reference_states
         (organization_id,source_id,instrument_id,state_json,trust_status,quality_score,state_version,updated_at,data_mode)
         VALUES (:org,:source,:instrument,:json,'TRUSTED',90,1,UTC_TIMESTAMP(6),'LIVE')"
    );
    foreach($instruments as $instrument){
        $json=json_encode([
            'instrument_id'=>$instrument,'source_id'=>$source,
            'quality_status'=>'TRUSTED','trust_status'=>'TRUSTED',
            'quality_score'=>90,'quality_flags'=>[],
            'quality'=>['status'=>'TRUSTED','score'=>90,'flags'=>[]],
            'state_version'=>1,'updated_at'=>gmdate(DATE_ATOM),
        ],JSON_THROW_ON_ERROR);
        $trading->execute([
            'org'=>$org,'venue'=>$venue,'instrument'=>$instrument,'source'=>$source,
            'json'=>$json,'fingerprint'=>str_repeat('a',64),
        ]);
        $reference->execute([
            'org'=>$org,'source'=>$source,'instrument'=>$instrument,'json'=>$json,
        ]);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    $invalidator=new StreamingTrustInvalidator($pdo);
    $changed=$invalidator->invalidateInstrument(
        $org,$source,$shard,$worker,$token,$instruments[0],'MASSIVE_SYMBOL_STALE',
    );
    $assert($changed===2,'Only the targeted equity trading/reference state pair should be changed.');

    $state=$pdo->prepare(
        'SELECT trust_status,quality_score,state_version,state_json FROM tn_capital_market_reference_states
         WHERE organization_id=:org AND source_id=:source AND instrument_id=:instrument'
    );
    $state->execute(['org'=>$org,'source'=>$source,'instrument'=>$instruments[0]]);
    $bad=$state->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($bad)&&$bad['trust_status']==='UNTRUSTED'&&(int)$bad['quality_score']===0
        &&(int)$bad['state_version']===2,'Target reference state was not revoked.');
    $badJson=json_decode((string)$bad['state_json'],true,64,JSON_THROW_ON_ERROR);
    $assert(in_array('INSTRUMENT_UNTRUSTED',$badJson['quality']['flags']??[],true)
        &&in_array('STREAM_MASSIVE_SYMBOL_STALE',$badJson['quality']['flags']??[],true),
        'Target reference state did not retain the invalidation reason.');

    $state->execute(['org'=>$org,'source'=>$source,'instrument'=>$instruments[1]]);
    $good=$state->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($good)&&$good['trust_status']==='TRUSTED'&&(int)$good['quality_score']===90
        &&(int)$good['state_version']===1,
        'Healthy sibling equity was modified by per-instrument invalidation.');

    $tradingState=$pdo->prepare(
        'SELECT trust_status,quality_score,state_version FROM tn_capital_market_states
         WHERE organization_id=:org AND source_id=:source AND venue_id=:venue AND instrument_id=:instrument'
    );
    $tradingState->execute([
        'org'=>$org,'source'=>$source,'venue'=>$venue,'instrument'=>$instruments[0],
    ]);
    $badTrading=$tradingState->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($badTrading)&&$badTrading['trust_status']==='UNTRUSTED'
        &&(int)$badTrading['quality_score']===0&&(int)$badTrading['state_version']===2,
        'Target trading state was not revoked consistently.');
    $tradingState->execute([
        'org'=>$org,'source'=>$source,'venue'=>$venue,'instrument'=>$instruments[1],
    ]);
    $goodTrading=$tradingState->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($goodTrading)&&$goodTrading['trust_status']==='TRUSTED'
        &&(int)$goodTrading['quality_score']===90&&(int)$goodTrading['state_version']===1,
        'Healthy sibling trading state was modified.');

    $healthRead=$pdo->prepare(
        'SELECT connection_state,failure_count,error_count,last_error
         FROM tn_capital_market_source_health WHERE organization_id=:org AND source_id=:source'
    );
    $healthRead->execute(['org'=>$org,'source'=>$source]);
    $healthRow=$healthRead->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($healthRow)&&$healthRow['connection_state']==='ACTIVE'
        &&(int)$healthRow['failure_count']===4&&(int)$healthRow['error_count']===2
        &&$healthRow['last_error']===null,
        'Per-instrument invalidation must not degrade shared transport health.');

    echo "Capital Markets per-instrument trust isolation MySQL integration passed.\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach(['tn_capital_market_states','tn_capital_market_reference_states','tn_capital_market_source_health',
        'tn_capital_market_stream_worker_leases'] as $table){
        $delete=$pdo->prepare('DELETE FROM '.$table.' WHERE organization_id=:org');
        $delete->execute(['org'=>$org]);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
