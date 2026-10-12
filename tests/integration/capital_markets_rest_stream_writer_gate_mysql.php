<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Exception\MarketSourceStreamingActiveException;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlMarketSourceWriterGate;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlStreamingWorkerLease;
use Infrastructure\Platform\Persistence\MySql\Transaction\TransactionManager;

if(getenv('COS_CM_WRITER_GATE_TEST')!=='1'){
    throw new RuntimeException('This integration test requires explicit COS_CM_WRITER_GATE_TEST=1.');
}
$autoload=getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/vendor/autoload.php';
require $autoload;
$url=getenv('DATABASE_URL');
if(!is_string($url)||$url==='')throw new RuntimeException('Missing CI MySQL DATABASE_URL.');
$parts=parse_url($url);
if(!is_array($parts)||($parts['scheme']??null)!=='mysql'){
    throw new RuntimeException('Integration test requires a MySQL connection.');
}
$host=$parts['host']??'mysql';
$port=(int)($parts['port']??3306);
$db=ltrim((string)($parts['path']??''),'/');
if($db===''||$host==='')throw new RuntimeException('Invalid MySQL test URL.');
$dsn='mysql:host='.$host.';port='.$port.';dbname='.$db.';charset=utf8mb4';
$make=static fn():PDO=>new PDO($dsn,rawurldecode((string)($parts['user']??'')),
    rawurldecode((string)($parts['pass']??'')),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
$workerPdo=$make();
$restPdo=$make();
$workerPdo->exec('SET SESSION innodb_lock_wait_timeout=1');
$restPdo->exec('SET SESSION innodb_lock_wait_timeout=1');
$lease=new MysqlStreamingWorkerLease($workerPdo);
$gate=new MysqlMarketSourceWriterGate($restPdo,new TransactionManager($restPdo));
$org='ci-cm-gate-'.bin2hex(random_bytes(5));
$otherOrg=$org.'-other';
$source='ci-streamed-source';
$shard='ci-ws-bbo';
$assert=static function(bool $condition,string $error):void{
    if(!$condition)throw new RuntimeException($error);
};
$wasRun=false;
try{
    // Worker acquired the source before the stale REST HTTP response arrived.
    $token=$lease->acquire($org,$source,$shard,'ws-worker',30);
    $assert($token!==null&&$token>0,'Streaming worker could not obtain initial source lease.');
    try{
        $gate->executeRest($org,$source,static function()use(&$wasRun):void{$wasRun=true;});
        throw new RuntimeException('REST was admitted despite active WS lease.');
    }catch(MarketSourceStreamingActiveException){
        $assert(!$wasRun,'REST callback executed on a WS-owned source.');
    }

    // A different tenant may still poll the same provider source name.
    $differentTenantRan=$gate->executeRest($otherOrg,$source,static fn():string=>'DIFFERENT_TENANT');
    $assert($differentTenantRan==='DIFFERENT_TENANT','Writer guard crossed tenant boundary.');

    $lease->release($org,$source,$shard,'ws-worker',$token);
    $assert($gate->executeRest($org,$source,static fn():string=>'REST_OK')==='REST_OK',
        'REST should resume after source streaming lease is released.');

    // A worker starting DURING REST's write transaction must block at the
    // source sentinel; the worker cannot leap over the in-flight commit.
    $blocked=false;
    $gate->executeRest($org,$source,function()use($lease,$org,$source,$shard,&$blocked):void{
        try{
            $candidate=$lease->acquire($org,$source,$shard,'racing-ws-worker',30);
            if($candidate!==null)throw new RuntimeException('Worker lease acquired during REST transaction.');
        }catch(PDOException $error){
            $mysqlCode=(int)($error->errorInfo[1]??0);
            if($mysqlCode!==1205)throw $error;
            $blocked=true;
        }
    });
    $assert($blocked,'REST publication did not block parallel WS acquisition.');
    $secondToken=$lease->acquire($org,$source,$shard,'racing-ws-worker',30);
    $assert($secondToken!==null&&$secondToken>$token,
        'Worker takeover after REST commit did not increment fencing token.');
    $lease->release($org,$source,$shard,'racing-ws-worker',$secondToken);

    // A source guard transaction must also roll back incidental DB writes.
    try{
        $gate->executeRest($org,$source,function()use($restPdo,$org,$source):void{
            $insert=$restPdo->prepare(
                "INSERT INTO tn_capital_market_stream_worker_leases
                 (organization_id,source_id,shard_key,owner_id,fencing_token,expires_at,updated_at)
                 VALUES (:org,:source,'ci-rollback','',0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))"
            );
            $insert->execute(['org'=>$org,'source'=>$source]);
            throw new DomainException('EXPECTED_ROLLBACK');
        });
        throw new RuntimeException('REST callback exception swallowed.');
    }catch(DomainException $error){
        $assert($error->getMessage()==='EXPECTED_ROLLBACK','Unexpected rollback failure.');
    }
    $test=$restPdo->prepare(
        "SELECT COUNT(*) FROM tn_capital_market_stream_worker_leases
         WHERE organization_id=:org AND source_id=:source AND shard_key='ci-rollback'"
    );
    $test->execute(['org'=>$org,'source'=>$source]);
    $assert((int)$test->fetchColumn()===0,'REST writer transaction did not roll back.');
    echo "Capital Markets REST/WS two-connection MySQL source arbitration passed.\n";
}finally{
    foreach([$restPdo,$workerPdo] as $pdo){
        if($pdo->inTransaction())$pdo->rollBack();
    }
    $cleanup=$workerPdo->prepare(
        'DELETE FROM tn_capital_market_stream_worker_leases
         WHERE organization_id IN (:org,:other) AND source_id=:source'
    );
    $cleanup->execute(['org'=>$org,'other'=>$otherOrg,'source'=>$source]);
}
