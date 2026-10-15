<?php
declare(strict_types=1);
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlStreamingOperationsSnapshot;
if(getenv('COS_CM_STREAM_OPS_TEST')!=='1')throw new RuntimeException('Explicit streaming operations MySQL flag required.');
require getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/vendor/autoload.php';
$parts=parse_url((string)getenv('DATABASE_URL'));
if(!is_array($parts)||($parts['scheme']??null)!=='mysql')throw new RuntimeException('MySQL test DSN required.');
$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$parts['host']??'mysql',
    (int)($parts['port']??3306),ltrim((string)($parts['path']??''),'/'));
$pdo=new PDO($dsn,rawurldecode((string)($parts['user']??'')),
    rawurldecode((string)($parts['pass']??'')),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
$org='ci-cm-ops-'.bin2hex(random_bytes(5));
$other=$org.'-foreign';
$source='ci-stream-ops';
$assert=static function(bool $ok,string $error):void{if(!$ok)throw new RuntimeException($error);};
try{
    $insert=$pdo->prepare(
        "INSERT INTO tn_capital_market_stream_worker_leases
          (organization_id,source_id,shard_key,owner_id,fencing_token,expires_at,updated_at)
         VALUES (:org,:source,:shard,:owner,3,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL :seconds SECOND),UTC_TIMESTAMP(6))"
    );
    foreach([
        [$org,'live','worker-1',60],
        [$org,'dead','worker-2',-60],
        [$org,'released','',-60],
        [$other,'foreign','worker-x',60],
    ] as [$tenant,$shard,$owner,$seconds]){
        $insert->execute(['org'=>$tenant,'source'=>$source,'shard'=>$shard,
            'owner'=>$owner,'seconds'=>$seconds]);
    }
    $report=(new MysqlStreamingOperationsSnapshot($pdo))->inspect($org,$source);
    $assert($report['lease_counts']===['ACTIVE'=>1,'EXPIRED'=>1,'RELEASED'=>1],
        'Streaming lease classification did not distinguish live/dead/released.');
    $assert(count($report['leases'])===3,'Foreign tenant lease leaked into operator view.');
    $serialized=json_encode($report,JSON_THROW_ON_ERROR);
    $assert(!str_contains($serialized,'worker-1')&&!str_contains($serialized,'worker-x'),
        'Streaming status leaked worker ownership identity.');
    $assert($report['operating_mode']==='READ_ONLY','Operator status must be read only.');
    $assert($pdo->inTransaction()===false,'Operator status opened a write transaction.');
    echo "Capital Markets streaming operations tenant and lease MySQL passed.\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    $delete=$pdo->prepare('DELETE FROM tn_capital_market_stream_worker_leases WHERE organization_id IN (:a,:b)');
    $delete->execute(['a'=>$org,'b'=>$other]);
}
