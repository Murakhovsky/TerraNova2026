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

$repo->recordSyncFailure($org,$id,'101','REMOTE_READ_FAILED');
$repo->recordSyncFailure($org,$id,'101','IMPORT_REJECTED');
$history=$repo->syncHistory($org,$id,10);
$assert(count($history)===4,'Two committed and two failed read-sync attempts expected.');
$assert(count(array_filter($history,static fn(array $r):bool=>$r['state']==='FAILED'))===2,
    'Failure receipts must remain operator-visible without altering successful snapshots.');
$denyAlien=false;
try{$repo->syncHistory($alien,$id,10);}catch(DomainException){$denyAlien=true;}
$assert($denyAlien,'Other tenant must not read Meta operator history.');
$assert($repo->account($org,$id)['last_synced_at']!==null,'A failure receipt must not reset last success.');
$assert($repo->insights($org,$id,'campaign',$from,$to)[0]['revision']===$beforeRevision,
    'A failed operator attempt must not change committed provider metrics.');
$source=$repo->spendEvidence($org,$id,$insights[0]['id']);
$expectedFields=[
    'ad_account_ref','source_snapshot_ref','grain','external_id','window_start','window_end',
    'timezone','currency','attribution_hash','source_hash','source_revision','source_status',
    'reported_spend_decimal'
];
$assert(array_keys($source)===$expectedFields,'Finance-facing evidence must have exact supported fields.');
$assert($source['ad_account_ref']===$id&&$source['source_snapshot_ref']===$insights[0]['id']
    &&$source['grain']==='campaign'&&$source['external_id']==='61000001'
    &&$source['timezone']==='Europe/Kyiv'&&$source['currency']==='USD'
    &&$source['reported_spend_decimal']==='12.340000'
    &&$source['source_status']==='PROVISIONAL'&&$source['source_revision']===1,
    'Source identity, amount, provenance or reporting fields mismatch.');
$assert($source['attribution_hash']===hash('sha256','meta-default-adset-attribution-v1'),
    'Attribution hash from persisted provider snapshot missing.');
$assert($source['source_hash']===hash('sha256',json_encode($valid[0],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),
    'Meta payload hash differs from the committed snapshot provenance.');
$assert(!str_contains(json_encode($source,JSON_THROW_ON_ERROR),'META_READ_ATOMIC_TEST'),
    'Spend evidence leaked the protected Vault ref.');
$unavailable=static function(string $tenant,string $account,string $snapshot)use($repo):bool{
    try{$repo->spendEvidence($tenant,$account,$snapshot);}
    catch(DomainException){return true;}
    return false;
};
$assert($unavailable($alien,$id,$source['source_snapshot_ref']),
    'Cross-tenant evidence readback escaped the Marketing source fence.');
$assert($unavailable($org,'mads-'.str_repeat('f',24),$source['source_snapshot_ref']),
    'Source snapshot can be rebound to a different ad account.');
$assert($unavailable($org,$id,'mis-'.str_repeat('0',24)),
    'Unknown source snapshot was promoted as a financial fact.');
$assert($unavailable($org,$id,'mis-../bad'),
    'Malformed source ref bypassed account fence.');

$revisedInsight=[...$valid[0],'spend'=>'13.750000'];
$repo->importInsights($org,$id,'campaign',$from,$to,[$revisedInsight]);
$revisedEvidence=$repo->spendEvidence($org,$id,$source['source_snapshot_ref']);
$assert($revisedEvidence['source_snapshot_ref']===$source['source_snapshot_ref']
    &&$revisedEvidence['source_revision']===2
    &&$revisedEvidence['source_status']==='REVISED'
    &&$revisedEvidence['source_hash']!==$source['source_hash']
    &&$revisedEvidence['reported_spend_decimal']==='13.750000',
    'Marketing revision update must be visible to Finance evidence consumers without another source ID.');
echo "Marketing Meta real MySQL atomic sync, rollback, replay, tenant and provider evidence PASS\n";
