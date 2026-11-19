<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require (getenv('COS_TEST_AUTOLOAD') ?: $root.'/vendor/autoload.php');

use Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidReadRepository;

$assert=static function(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);};
$deny=static function(callable $action,string $reason):void {
    try {$action();} catch(DomainException) {return;}
    throw new RuntimeException($reason);
};

$dsn=sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST')?:'127.0.0.1',
    getenv('DB_PORT')?:'3306',
    getenv('DB_DATABASE')?:'cos');
$db=new PDO($dsn,getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$repo=new MysqlMarketingPaidReadRepository($db);
$org='marketing-atomic-a-'.bin2hex(random_bytes(5));
$alien='marketing-atomic-b-'.bin2hex(random_bytes(5));
$account=$repo->registerAccount($org,'env://META_READ_ATOMIC_TEST',[
    'ad_account_id'=>'act_'.random_int(100000000000,999999999999),
    'currency'=>'USD','timezone'=>'Europe/Kyiv',
    'connection_status'=>'READ_ONLY','capability_scope'=>['ads_read']
]);
$id=$account['id'];
$assert(str_starts_with($id,'mads-'),'Test fixture did not create Meta account.');
$from='2026-11-01';$to='2026-11-07';
$hierarchy=[
    'campaigns'=>[['id'=>'61000001','name'=>'Safe read fixture','effective_status'=>'PAUSED','objective'=>'OUTCOME_TRAFFIC']],
    'adsets'=>[['id'=>'62000001','campaign_id'=>'61000001','name'=>'Set','effective_status'=>'PAUSED']],
    'ads'=>[['id'=>'63000001','adset_id'=>'62000001','name'=>'Ad','effective_status'=>'PAUSED']]
];
$valid=[
    ['campaign_id'=>'61000001','date_start'=>$from,'date_stop'=>$to,'spend'=>'12.340000',
        'impressions'=>'500','clicks'=>'5']
];
$bad=[['campaign_id'=>'61000001','date_start'=>$from,'date_stop'=>$to,'spend'=>'-500.00']];
$deny(static fn()=> $repo->importSnapshot($org,$id,$from,$to,$hierarchy,$bad),
    'Malformed spend must prevent atomic Meta sync.');
$assert(!$db->inTransaction(),'Failed sync left a leaked transaction.');
$assert($repo->hierarchy($org,$id)['campaigns']===[],
    'Partial Meta campaign from failed insight import must rollback.');
$assert($repo->insights($org,$id,'campaign',$from,$to)===[],
    'Failed Meta sync inserted partial spend.');
$assert($repo->account($org,$id)['last_synced_at']===null,'Failed sync marked account current.');
$q=$db->prepare('SELECT COUNT(*) FROM marketing_paid_sync_runs WHERE organization_id=? AND account_id=?');
$q->execute([$org,$id]);
$assert((int)$q->fetchColumn()===0,'Failed sync must not create a SUCCEEDED receipt.');

$counts=$repo->importSnapshot($org,$id,$from,$to,$hierarchy,$valid);
$assert($counts===['campaigns'=>1,'adsets'=>1,'ads'=>1,'insights'=>1],
    'Atomic Meta import did not commit every record.');
$assert(!$db->inTransaction(),'Successful sync leaked a DB transaction.');
$read=$repo->hierarchy($org,$id);
$assert(count($read['campaigns'])===1&&count($read['adsets'])===1&&count($read['ads'])===1,
    'Meta hierarchy failed to commit together.');
$insights=$repo->insights($org,$id,'campaign',$from,$to);
$assert(count($insights)===1 && $insights[0]['external_id']==='61000001'
    &&(string)$insights[0]['spend_decimal']==='12.340000','Committed insight value does not match provider snapshot.');
$assert($repo->account($org,$id)['last_synced_at']!==null,'Successful sync timestamp missing.');
$q->execute([$org,$id]);$assert((int)$q->fetchColumn()===1,'One successful sync must produce one receipt.');
$beforeRevision=$insights[0]['revision'];
$repo->importSnapshot($org,$id,$from,$to,$hierarchy,$valid);
$insights=$repo->insights($org,$id,'campaign',$from,$to);
$assert(count($insights)===1 && $insights[0]['revision']===$beforeRevision,
    'Repeated identical provider observations must not double-count or revise spend.');
$assert($repo->hierarchy($alien,$id)['campaigns']===[],
    'Other tenant saw Meta imported hierarchy.');
$assert($repo->insights($alien,$id,'campaign',$from,$to)===[],
    'Other tenant saw Meta imported insight snapshots.');

$modifiedHierarchy=$hierarchy;
$modifiedHierarchy['campaigns'][0]['name']='Must NOT overwrite prior committed campaign';
$badSecond=[
    ['campaign_id'=>'61000001','date_start'=>$from,'date_stop'=>$to,'spend'=>'55.50'],
    ['campaign_id'=>'61000002','date_start'=>$from,'date_stop'=>'2026-11-08','spend'=>'4.00']
];
$deny(static fn()=> $repo->importSnapshot($org,$id,$from,$to,$modifiedHierarchy,$badSecond),
    'One failed row must rollback the entire insights batch and hierarchy update.');
$assert(!$db->inTransaction(),'Mid-batch validation failure leaked transaction.');
$after=$repo->hierarchy($org,$id);
$assert($after['campaigns'][0]['name']==='Safe read fixture',
    'Mid-batch error corrupted an already committed campaign.');
$stored=$repo->insights($org,$id,'campaign',$from,$to);
$assert(count($stored)===1 && $stored[0]['revision']===$beforeRevision
    &&(string)$stored[0]['spend_decimal']==='12.340000',
    'Mid-batch failure changed committed spend/insight revision.');
$q->execute([$org,$id]);$assert((int)$q->fetchColumn()===2,
    'Failed sync after a committed one must not create a success receipt.');

echo "Marketing Meta real MySQL atomic sync, rollback, replay, tenant and provider evidence PASS\n";
