<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Contract\StreamingTrustInvalidatorInterface;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlStreamingExpiredLeaseSweeper;

if(getenv('COS_CM_EXPIRED_SWEEP_TEST')!=='1'){
    throw new RuntimeException('Explicit MySQL stream sweep integration test flag required.');
}
require getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/vendor/autoload.php';
$url=getenv('DATABASE_URL');
$parts=is_string($url)?parse_url($url):false;
if(!is_array($parts)||($parts['scheme']??null)!=='mysql'){
    throw new RuntimeException('The stream sweep integration test requires MySQL DATABASE_URL.');
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
$org='ci-cm-sweep-'.bin2hex(random_bytes(6));
$otherOrg=$org.'-different';
$source='ci-streaming-dead';
$shards=MysqlStreamingExpiredLeaseSweeper::PILOT_SHARDS;
$failedShard='bybit-linear-single-bbo';
$insert=$pdo->prepare(
    'INSERT INTO tn_capital_market_stream_worker_leases
     (organization_id,source_id,shard_key,owner_id,fencing_token,expires_at,updated_at)
     VALUES (:org,:source,:shard,:owner,3,
             DATE_ADD(UTC_TIMESTAMP(6),INTERVAL :seconds SECOND),UTC_TIMESTAMP(6))'
);
$add=static function(string $tenant,string $src,string $shard,int $offset)
    use($insert):void{
    $insert->execute([
        'org'=>$tenant,'source'=>$src,'shard'=>$shard,
        'owner'=>'dead-ws-worker','seconds'=>$offset,
    ]);
};
$health=new class($pdo,$failedShard) implements StreamingTrustInvalidatorInterface{
    /** @var list<string> */
    public array $completed=[];
    public bool $failOnce=true;
    public function __construct(private PDO $pdo,private string $failedShard){}
    public function invalidate(string $org,string $source,string $shard,string $worker,int $token,string $reason):int
    {
        if($reason!=='LEASE_EXPIRED')throw new RuntimeException('Wrong recovery reason.');
        $check=$this->pdo->prepare(
            'SELECT fencing_token FROM tn_capital_market_stream_worker_leases
             WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
               AND owner_id=:worker AND fencing_token=:token
               AND expires_at>UTC_TIMESTAMP(6)'
        );
        $check->execute([
            'org'=>$org,'source'=>$source,'shard'=>$shard,
            'worker'=>$worker,'token'=>$token,
        ]);
        if($check->fetchColumn()===false){
            throw new RuntimeException('Invalidator called without current fenced ownership.');
        }
        if($this->failOnce && $shard===$this->failedShard){
            $this->failOnce=false;
            throw new RuntimeException('Simulated trust store failure after takeover.');
        }
        $this->completed[]=$org.'/'.$shard;
        return 1;
    }
};
$sweeper=new MysqlStreamingExpiredLeaseSweeper($pdo,$health);
$select=$pdo->prepare(
    'SELECT owner_id,fencing_token,expires_at>UTC_TIMESTAMP(6) AS alive
     FROM tn_capital_market_stream_worker_leases
     WHERE organization_id=:org AND source_id=:source AND shard_key=:shard'
);
$read=static function(string $tenant,string $shard)use($select,$source):array{
    $select->execute(['org'=>$tenant,'source'=>$source,'shard'=>$shard]);
    $row=$select->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))throw new RuntimeException('Expected lease row missing.');
    return $row;
};
try{
    foreach($shards as $shard)$add($org,$source,$shard,-10);
    $add($org,$source,'unrecognized-provider-transport',-10);
    $add($otherOrg,$source,'kraken-spot-single-bbo',-10);
    $add($org,$source,'__source_writer_gate__',-10);
    $add($org,$source,'future-active-provider',100);
    $out=$sweeper->sweepBatch(100,$org);
    $assert($out['examined']===count($shards),'Batch did not discover precisely supported expired pilots.');
    $assert($out['failed']===1&&$out['invalidated']===count($shards)-1,
        'Scanner did not report and retain simulated trust-store failure.');
    $assert(count($health->completed)===count($shards)-1,'Successful candidates were not invalidated.');

    foreach($shards as $shard){
        $row=$read($org,$shard);
        $assert((int)$row['fencing_token']===4,'Expired lease did not advance monotonic fencing.');
        if($shard===$failedShard){
            $assert(str_starts_with($row['owner_id'],'cm-lease-sweep-') && (int)$row['alive']===1,
                'Failed invalidation lost the emergency owner before retry.');
        }else{
            $assert($row['owner_id']==='','Successful sweep did not release emergency ownership.');
        }
    }
    $assert($read($org,'unrecognized-provider-transport')['owner_id']==='dead-ws-worker',
        'Unknown transport shard was swept without explicit support.');
    $assert($read($org,'__source_writer_gate__')['owner_id']==='dead-ws-worker',
        'Source sentinel must not be claimed as a data worker.');
    $assert($read($otherOrg,'kraken-spot-single-bbo')['owner_id']==='dead-ws-worker',
        'Tenant filter leaked across organization boundary.');
    $assert($sweeper->sweepBatch(100,$org)['examined']===0,
        'Unexpired emergency owner was scanned again before retry deadline.');

    $expire=$pdo->prepare(
        'UPDATE tn_capital_market_stream_worker_leases
         SET expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND)
         WHERE organization_id=:org AND source_id=:source AND shard_key=:shard'
    );
    $expire->execute(['org'=>$org,'source'=>$source,'shard'=>$failedShard]);
    $retry=$sweeper->sweepBatch(100,$org);
    $assert($retry['examined']===1&&$retry['invalidated']===1&&$retry['failed']===0,
        'A previous invalidation error could not be recovered on next expiry.');
    $assert($read($org,$failedShard)['owner_id']==='','Recovered expired sweeper lease not released.');
    $assert((int)$read($org,$failedShard)['fencing_token']===5,
        'Retry did not advance fencing generation.');
    $other=$sweeper->sweepBatch(100,$otherOrg);
    $assert($other['examined']===1&&$other['invalidated']===1,
        'Other tenant could not independently repair its own dead Kraken lease.');
    echo "All-provider streaming expired lease MySQL integration passed.\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    $clean=$pdo->prepare(
        'DELETE FROM tn_capital_market_stream_worker_leases
         WHERE organization_id IN (:org,:other) AND source_id=:source'
    );
    $clean->execute(['org'=>$org,'other'=>$otherOrg,'source'=>$source]);
}
