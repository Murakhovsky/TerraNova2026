<?php
declare(strict_types=1);
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\MysqlInstrumentPoolSnapshotRepository;
if(getenv('COS_CM_POOL_SNAPSHOT_TEST')!=='1')throw new RuntimeException('Explicit Instrument Pool MySQL test flag required.');
require getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/vendor/autoload.php';
$parts=parse_url((string)getenv('DATABASE_URL'));
if(!is_array($parts)||($parts['scheme']??null)!=='mysql')throw new RuntimeException('MySQL fixture URL required.');
$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $parts['host']??'mysql',(int)($parts['port']??3306),ltrim((string)($parts['path']??''),'/'));
$pdo=new PDO($dsn,rawurldecode((string)($parts['user']??'')),
    rawurldecode((string)($parts['pass']??'')),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
$org='ci-pool-'.bin2hex(random_bytes(5));
$foreign=$org.'-foreign';
$venue='venue:ci:pool';
$assert=static function(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);};
try{
    $add=$pdo->prepare(
        "INSERT INTO tn_capital_market_venues
         (organization_id,venue_id,name,code,venue_type,status,timezone,metadata_json)
         VALUES (:org,:venue,'CI Pool','CIPOOL','cex','ACTIVE','UTC','{}')"
    );
    foreach([$org,$foreign] as $tenant)$add->execute(['org'=>$tenant,'venue'=>$venue]);
    $repo=new MysqlInstrumentPoolSnapshotRepository($pdo);
    $assert($repo->reserveScan($org,'BINANCE',$venue),'First scan should acquire cooldown gate.');
    $assert(!$repo->reserveScan($org,'BINANCE',$venue),'Second scan bypassed atomic cooldown.');
    $assert($repo->reserveScan($foreign,'BINANCE',$venue),'One tenant blocked independent tenant.');
    $snapshot=[
        'provider'=>'BINANCE','venue_id'=>$venue,'health'=>'AVAILABLE',
        'evidence_sha256'=>str_repeat('a',64),'provider_count'=>2,'mapping_count'=>1,
        'counts'=>['MAPPED_ACTIVE_OBSERVED'=>1,'UNMAPPED_REVIEW'=>1],
        'rows'=>[['venue_symbol'=>'BTCUSDT','observation'=>'UNMAPPED_REVIEW']],
        'truncated'=>false,'dry_run'=>true,'executable'=>false,
    ];
    $id=$repo->record($org,19,$snapshot);
    $assert($id>0,'Snapshot was not stored.');
    $stored=$repo->latest($org,'BINANCE',$venue);
    $assert(($stored['snapshot_id']??null)===$id
        &&($stored['evidence_sha256']??null)===str_repeat('a',64),
        'Evidence hash lost from stored snapshot.');
    $assert($repo->latest($foreign,'BINANCE',$venue)===null,
        'Tenant isolation failed on snapshot read.');
    $unsafe=$snapshot;$unsafe['executable']=true;
    $rejected=false;
    try{$repo->record($org,19,$unsafe);}catch(DomainException){$rejected=true;}
    $assert($rejected,'Unsafe executable evidence accepted.');
    $unavailable=$snapshot;
    $unavailable['health']='UNAVAILABLE';
    $unavailable['provider_count']=null;
    $unavailable['evidence_sha256']=null;
    $unavailable['counts']=null;
    $unavailable['rows']=[];
    $assert($repo->record($org,19,$unavailable)>0,
        'Provider outage evidence was lost.');
    $assert(($repo->latest($org,'BINANCE',$venue)['health']??null)==='UNAVAILABLE',
        'Provider outage was misrepresented as normal listing.');
    $assert($repo->latest($org,'KRAKEN',$venue)===null,
        'Provider isolation failed.');
    echo "Instrument Pool snapshot cooldown, evidence and MySQL tenant isolation passed.\n";
}finally{
    foreach([
        'tn_capital_market_instrument_pool_snapshots',
        'tn_capital_market_instrument_pool_scan_gates',
        'tn_capital_market_venues',
    ] as $table){
        $del=$pdo->prepare('DELETE FROM '.$table.' WHERE organization_id IN (:a,:b)');
        $del->execute(['a'=>$org,'b'=>$foreign]);
    }
}
