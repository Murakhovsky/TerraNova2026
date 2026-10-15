<?php
declare(strict_types=1);
use App\Kernel;
use Domains\CapitalMarkets\Application\Service\InstrumentPoolApprovalService;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\MysqlInstrumentPoolSnapshotRepository;

if(getenv('COS_CM_POOL_APPROVAL_TEST')!=='1')throw new RuntimeException('Explicit MySQL acceptance flag required.');
require getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/vendor/autoload.php';
$parts=parse_url((string)getenv('DATABASE_URL'));
if(!is_array($parts)||($parts['scheme']??null)!=='mysql')throw new RuntimeException('MySQL DSN required.');
$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $parts['host']??'mysql',(int)($parts['port']??3306),
    ltrim((string)($parts['path']??''),'/')),
    rawurldecode((string)($parts['user']??'')),rawurldecode((string)($parts['pass']??'')),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
putenv('APP_ENV=test');$_ENV['APP_ENV']='test';$_SERVER['APP_ENV']='test';
$kernel=new Kernel('test',true);$kernel->boot();
$service=$kernel->getContainer()->get(InstrumentPoolApprovalService::class);
if(!$service instanceof InstrumentPoolApprovalService)throw new RuntimeException('Approval service unavailable from real Symfony container.');
$org='ci-pool-approve-'.bin2hex(random_bytes(4));$other=$org.'-foreign';
$venue='venue:ci:approvals';
$symbol='CI'.strtoupper(bin2hex(random_bytes(4))).'USDT';
$id='pool:'.strtolower($symbol);
$proposer=0;$approver=0;$foreignActor=0;
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$reject=static function(callable $fn,string $message)use($assert):void{
    $raised=false;try{$fn();}catch(DomainException|\PDOException|\ValueError){$raised=true;}
    $assert($raised,$message);
};
$snapshotRepo=new MysqlInstrumentPoolSnapshotRepository($pdo);
$record=static function(array $rows,int $mappedCount=0,bool $available=true)
    use($snapshotRepo,$org,$venue,&$proposer):int{
    return $snapshotRepo->record($org,$proposer,[
        'provider'=>'BINANCE','venue_id'=>$venue,
        'health'=>$available?'AVAILABLE':'UNAVAILABLE',
        'evidence_sha256'=>$available?str_repeat('a',64):null,
        'provider_count'=>$available?1:null,'mapping_count'=>$mappedCount,
        'counts'=>$available?[
            'MAPPED_ACTIVE_OBSERVED'=>0,'MAPPED_STATUS_REVIEW'=>0,
            'MAPPED_NOT_OBSERVED_REVIEW'=>0,'UNMAPPED_REVIEW'=>count($rows),
        ]:null,
        'rows'=>$rows,'truncated'=>false,'mapped_coverage_complete'=>true,
        'dry_run'=>true,'executable'=>false,
    ]);
};
$select=static function(string $table,string $col,string $org,string $id)use($pdo):?string{
    $stmt=$pdo->prepare("SELECT ".$col." FROM ".$table." WHERE organization_id=:org AND instrument_id=:id LIMIT 1");
    $stmt->execute(['org'=>$org,'id'=>$id]);
    $value=$stmt->fetchColumn();return $value===false?null:(string)$value;
};
try{
    $createOrg=$pdo->prepare(
        "INSERT INTO cos_organizations(id,name,status) VALUES(:id,'CI Pool Approval','ACTIVE')"
    );
    foreach([$org,$other] as $tenant)$createOrg->execute(['id'=>$tenant]);
    $insertUser=$pdo->prepare(
        "INSERT INTO tn_users(organization_id,email,password_hash,full_name,role,status)
         VALUES(:org,:email,:hash,'CI Pool Acceptance','manager','active')"
    );
    $join=$pdo->prepare(
        "INSERT INTO cos_organization_memberships(organization_id,user_id,role,status)
         VALUES(:org,:user,'manager','ACTIVE')"
    );
    foreach([[$org,'proposer'],[$org,'approver'],[$other,'foreign']] as [$tenant,$role]){
        $insertUser->execute([
            'org'=>$tenant,'email'=>'ci.pool.'.bin2hex(random_bytes(8)).'@example.invalid',
            'hash'=>password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT),
        ]);
        $user=(int)$pdo->lastInsertId();
        $join->execute(['org'=>$tenant,'user'=>$user]);
        if($role==='proposer')$proposer=$user;
        if($role==='approver')$approver=$user;
        if($role==='foreign')$foreignActor=$user;
    }
    $venues=$pdo->prepare(
        "INSERT INTO tn_capital_market_venues
        (organization_id,venue_id,name,code,venue_type,status,timezone,metadata_json)
        VALUES (:org,:venue,'CI Pool Approval','CIAPP','cex','ACTIVE','UTC','{}')"
    );
    foreach([$org,$other] as $tenant)$venues->execute(['org'=>$tenant,'venue'=>$venue]);
    $grant=$pdo->prepare(
        "INSERT INTO capital_market_user_capabilities
        (organization_id,user_id,capability,status,granted_by)
        VALUES (:org,:user,:cap,'ACTIVE','ci-approval')"
    );
    foreach([[$org,$proposer],[$org,$approver],[$other,$foreignActor]] as [$tenant,$user]){
        foreach(['capital_markets.instrument_pool.view','capital_markets.instrument_pool.propose',
            'capital_markets.instrument_pool.approve'] as $cap){
            $grant->execute(['org'=>$tenant,'user'=>$user,'cap'=>$cap]);
        }
    }
    $s1=$record([[
        'venue_symbol'=>$symbol,'instrument_id'=>null,'mapping_status'=>null,
        'observation'=>'UNMAPPED_REVIEW','base'=>'CI','quote'=>'USDT',
    ]]);
    $change=$service->propose($org,$proposer,[
        'snapshot_id'=>$s1,'action'=>'CREATE','venue_symbol'=>$symbol,
        'instrument_id'=>$id,'symbol'=>$symbol,'canonical_symbol'=>$symbol,
        'name'=>'Independent integration test spot','family'=>'spot_market',
        'reason'=>'Explicit verified symbol onboarding',
    ],'CI-POOL-CREATE');
    $assert($change>0,'No pending import.');
    $assert(count($service->queue($other,$foreignActor))===0,'Cross-tenant proposal leaked.');
    $reject(fn()=>$service->decide($org,$proposer,$change,true,
        'Self approval should fail','CI-SELF'),'Self approval bypassed.');
    $assert($select('tn_capital_market_instruments','status',$org,$id)===null,
        'Proposer executed pending import.');
    $approved=$service->decide($org,$approver,$change,true,
        'Independent verified onboarding','CI-POOL-APPROVE');
    $assert($approved['status']==='APPLIED','Approval not persisted.');
    $assert($select('tn_capital_market_instruments','status',$org,$id)==='ACTIVE',
        'Approved canonical instrument not created.');
    $assert($select('tn_capital_market_venue_instruments','status',$org,$id)==='SUSPENDED',
        'New venue mapping was not safety-quarantined.');
    $reject(fn()=>$service->decide($org,$approver,$change,true,
        'Replay approval should fail','CI-REPLAY'),'Approval replay was accepted.');
    $reject(fn()=>$service->decide($other,$foreignActor,$change,true,
        'Foreign decision should fail','CI-TENANT'),'Foreign org approved change.');
    $s2=$record([[
        'venue_symbol'=>$symbol,'instrument_id'=>$id,'mapping_status'=>'SUSPENDED',
        'observation'=>'MAPPED_STATUS_REVIEW','base'=>'CI','quote'=>'USDT',
    ]],1);
    $activation=$service->propose($org,$proposer,[
        'snapshot_id'=>$s2,'action'=>'STATUS','venue_symbol'=>$symbol,'target_status'=>'ACTIVE',
        'reason'=>'Current live listing verified explicitly',
    ],'CI-POOL-ACTIVATE');
    $service->decide($org,$approver,$activation,true,'Enable verified mapping','CI-ACTIVATE');
    $assert($select('tn_capital_market_venue_instruments','status',$org,$id)==='ACTIVE',
        'Approved mapping activation failed.');
    $missing=[
        'venue_symbol'=>$symbol,'instrument_id'=>$id,'mapping_status'=>'ACTIVE',
        'observation'=>'MAPPED_NOT_OBSERVED_REVIEW','base'=>null,'quote'=>null,
    ];
    $s3=$record([$missing],1);
    $reject(fn()=>$service->propose($org,$proposer,[
        'snapshot_id'=>$s3,'action'=>'STATUS','venue_symbol'=>$symbol,'target_status'=>'SUSPENDED',
        'reason'=>'Single missing observation is insufficient',
    ],'CI-MISSING-ONE'),'Single absence improperly authorized suspension.');
    $record([$missing],1);$s5=$record([$missing],1);
    $suspension=$service->propose($org,$proposer,[
        'snapshot_id'=>$s5,'action'=>'STATUS','venue_symbol'=>$symbol,'target_status'=>'SUSPENDED',
        'reason'=>'Three independent full healthy observations',
    ],'CI-POOL-SUSPEND');
    $record([],1,false);
    $reject(fn()=>$service->decide($org,$approver,$suspension,true,
        'Provider outage should not authorize changes','CI-FAILCLOSED'),
        'Provider outage allowed canonical suspension.');
    $assert($select('tn_capital_market_venue_instruments','status',$org,$id)==='ACTIVE',
        'Failed approval unexpectedly mutated canonical mapping.');
    $service->decide($org,$approver,$suspension,false,
        'Evidence became unavailable for safe approval','CI-REJECT');
    $assert($service->queue($org,$proposer)[0]['status']==='REJECTED',
        'Rejection was not persisted in governance queue.');
    echo "Instrument Pool MySQL approval, no-self-approval, import, mappings, lifecycle and outage passed.\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    $tables=[
        'tn_capital_market_instrument_pool_changes',
        'tn_capital_market_instrument_pool_snapshots',
        'tn_capital_market_instrument_pool_scan_gates',
        'tn_capital_market_subscriptions',
        'tn_capital_market_states',
        'tn_capital_market_data_sources',
        'tn_capital_market_venue_instruments',
        'tn_capital_market_instrument_identifiers',
        'tn_capital_market_instruments',
        'capital_market_user_capabilities',
        'tn_capital_market_venues',
        'cos_organization_memberships',
        'tn_users',
        'cos_organizations',
    ];
    foreach($tables as $table){
        $delete=$pdo->prepare('DELETE FROM '.$table.' WHERE organization_id IN (:a,:b)');
        $delete->execute(['a'=>$org,'b'=>$other]);
    }
    $kernel->shutdown();
}
