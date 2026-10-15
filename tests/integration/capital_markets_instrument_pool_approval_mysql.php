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

    // Explicit economic identity binding to a separately pre-existing instrument.
    $linkSymbol='LNK'.strtoupper(bin2hex(random_bytes(3))).'USDT';
    $linkedId='pool:linked:'.strtolower($linkSymbol);
    $pdo->prepare(
        "INSERT INTO tn_capital_market_instruments
         (organization_id,instrument_id,symbol,canonical_symbol,name,family,status,
          metadata_json,created_at,updated_at)
         VALUES (:org,:id,:symbol,:canonical_symbol,'Existing canonical symbol',
          'spot_market','ACTIVE','{}',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))"
    )->execute(['org'=>$org,'id'=>$linkedId,'symbol'=>$linkSymbol,'canonical_symbol'=>$linkSymbol]);
    $linkSnapshot=$record([[
        'venue_symbol'=>$linkSymbol,'instrument_id'=>null,'mapping_status'=>null,
        'observation'=>'UNMAPPED_REVIEW','base'=>'LNK','quote'=>'USDT',
    ]],1);
    $reject(fn()=>$service->propose($org,$proposer,[
        'snapshot_id'=>$linkSnapshot,'action'=>'LINK','venue_symbol'=>$linkSymbol,
        'instrument_id'=>'missing:instrument',
        'reason'=>'Unverified symbol cannot link to unknown asset',
    ],'CI-INVALID-LINK'),'Unknown canonical identity was accepted as a LINK.');
    $linkChange=$service->propose($org,$proposer,[
        'snapshot_id'=>$linkSnapshot,'action'=>'LINK','venue_symbol'=>$linkSymbol,
        'instrument_id'=>$linkedId,'reason'=>'Operator verified existing instrument identity',
    ],'CI-LINK');
    $assert($select('tn_capital_market_venue_instruments','status',$org,$linkedId)===null,
        'Unapproved LINK changed canonical registry.');
    $service->decide($org,$approver,$linkChange,true,
        'Approved separately verified canonical association','CI-LINK-APPROVE');
    $assert($select('tn_capital_market_venue_instruments','status',$org,$linkedId)==='SUSPENDED',
        'Approved LINK must create a suspended venue mapping.');

    // Autonomous exact-link proposals are permitted ONLY when an active
    // canonical instrument has a pre-registered, venue-specific identity.
    $explicitSymbol='AUT'.strtoupper(bin2hex(random_bytes(3))).'USDT';
    $explicitId='pool:explicit:'.strtolower($explicitSymbol);
    $pdo->prepare(
        "INSERT INTO tn_capital_market_instruments
         (organization_id,instrument_id,symbol,canonical_symbol,name,family,status,
          metadata_json,created_at,updated_at)
         VALUES (:org,:id,:symbol,:canonical_symbol,'Explicit issuer identity',
          'spot_market','ACTIVE','{}',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))"
    )->execute(['org'=>$org,'id'=>$explicitId,'symbol'=>$explicitSymbol,
        'canonical_symbol'=>$explicitSymbol]);
    $pdo->prepare(
        "INSERT INTO tn_capital_market_instrument_identifiers
          (organization_id,instrument_id,identifier_type,identifier_source,identifier_value)
          VALUES (:org,:id,'EXCHANGE_SYMBOL',:source,:symbol)"
    )->execute([
        'org'=>$org,'id'=>$explicitId,
        'source'=>InstrumentPoolApprovalService::exactIdentifierSource('BINANCE',$venue),
        'symbol'=>$explicitSymbol,
    ]);
    $explicitSnapshot=$record([[
        'venue_symbol'=>$explicitSymbol,'instrument_id'=>null,'mapping_status'=>null,
        'observation'=>'UNMAPPED_REVIEW','base'=>'AUT','quote'=>'USDT',
    ]],2);
    $exact=$service->stageExactIdentityLinks($org,$proposer,$explicitSnapshot,5);
    $assert($exact['created']===1,'Exact registered exchange identity was not staged.');
    $assert($select('tn_capital_market_venue_instruments','status',$org,$explicitId)===null,
        'Automatic link staging bypassed human approval.');
    $exactAgain=$service->stageExactIdentityLinks($org,$proposer,$explicitSnapshot,5);
    $assert($exactAgain['created']===0,'Duplicate automatic mapping proposal was staged.');
    $exactId=(int)$service->queue($org,$proposer)[0]['id'];
    $reject(fn()=>$service->decide($org,$proposer,$exactId,true,
        'Service actor must not self approve','CI-EXACT-SELF'),
        'Auto mapping circumvented independent human approval.');
    $service->decide($org,$approver,$exactId,true,
        'Verified exact venue identity mapping','CI-EXACT-LINK');
    $assert($select('tn_capital_market_venue_instruments','status',$org,$explicitId)==='SUSPENDED',
        'Approved exact identity LINK must remain SUSPENDED.');
    $assert($service->stageExactIdentityLinks($org,$proposer,$explicitSnapshot,5)['created']===0,
        'Existing venue mapping was proposed again.');

    $reject(fn()=>$service->decide($other,$foreignActor,$change,true,
        'Foreign decision should fail','CI-TENANT'),'Foreign org approved change.');
    $candidateSet=$service->stageStatusCandidates($org,$proposer,$s1);
    $assert($candidateSet['created']===0,'Automated pool staging must not infer new instrument identities.');
    $s2=$record([[
        'venue_symbol'=>$symbol,'instrument_id'=>$id,'mapping_status'=>'SUSPENDED',
        'observation'=>'MAPPED_STATUS_REVIEW','base'=>'CI','quote'=>'USDT',
    ]],1);
    $auto=$service->stageStatusCandidates($org,$proposer,$s2,5);
    $assert($auto['created']===1,'Observed suspended mapping did not generate approval request.');
    $autoRepeat=$service->stageStatusCandidates($org,$proposer,$s2,5);
    $assert($autoRepeat['created']===0,'Duplicate auto proposals were created.');
    $rows=$service->queue($org,$proposer);
    $autoId=(int)$rows[0]['id'];
    $service->decide($org,$approver,$autoId,false,
        'Operator explicitly rejects automated activation','CI-AUTO-REJECT');
    // Re-proposing after rejection requires new catalog evidence.
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

    // Seed a previously trusted quote and active subscription: a later
    // approved suspension must revoke both in the SAME transaction.
    $source='ci-pool-approval-source';
    $pdo->prepare(
        "INSERT INTO tn_capital_market_data_sources
         (organization_id,source_id,venue_id,adapter_type,enabled,priority,roles_json,
          rate_limit_policy_json,reconnect_policy_json,health_policy_json,
          license_profile,metadata_json)
         VALUES (:org,:source,:venue,'test',0,100,'[]','{}','{}','{}','UNSPECIFIED','{}')"
    )->execute(['org'=>$org,'source'=>$source,'venue'=>$venue]);
    $pdo->prepare(
        "INSERT INTO tn_capital_market_states
         (organization_id,venue_id,instrument_id,source_id,state_json,trust_status,
          quality_score,state_version,source_timestamp,updated_at,
          last_sequence,last_event_fingerprint,data_mode)
         VALUES (:org,:venue,:id,:source,'{}','TRUSTED',95,2,UTC_TIMESTAMP(6),
          UTC_TIMESTAMP(6),NULL,:fingerprint,'LIVE')"
    )->execute(['org'=>$org,'venue'=>$venue,'id'=>$id,'source'=>$source,
        'fingerprint'=>str_repeat('b',64)]);
    $pdo->prepare(
        "INSERT INTO tn_capital_market_subscriptions
         (organization_id,subscription_id,source_id,venue_id,instrument_id,
          target_fingerprint,data_type,status,subscribed_at)
         VALUES (:org,:sub,:source,:venue,:id,:fingerprint,'BBO','ACTIVE',UTC_TIMESTAMP(6))"
    )->execute(['org'=>$org,'sub'=>'sub:'.bin2hex(random_bytes(8)),'source'=>$source,
        'venue'=>$venue,'id'=>$id,'fingerprint'=>hash('sha256',$org.'|'.$source.'|'.$id)]);

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
    $autoSuspend=$service->stageStatusCandidates($org,$proposer,$s5,5);
    $assert($autoSuspend['created']===1,
        'Three healthy absent catalog snapshots did not stage a controlled suspension.');
    $autoPending=(int)$service->queue($org,$proposer)[0]['id'];
    $service->decide($org,$approver,$autoPending,false,
        'Operator reserves right to reject suspicious disappearance','CI-AUTO-SUSPEND-REJECT');
    $s5=$record([$missing],1);
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

    // The outage interrupted the chain. Only three NEW healthy observations
    // allow a fresh suspension proposal. This exercises actual DB revocation.
    $record([$missing],1);$record([$missing],1);$last=$record([$missing],1);
    $fresh=$service->propose($org,$proposer,[
        'snapshot_id'=>$last,'action'=>'STATUS','venue_symbol'=>$symbol,
        'target_status'=>'SUSPENDED',
        'reason'=>'Three fresh independent healthy absence observations',
    ],'CI-RECHECK');
    $service->decide($org,$approver,$fresh,true,
        'Review completed and mapping must be suspended','CI-SUSPEND');
    $assert($select('tn_capital_market_venue_instruments','status',$org,$id)==='SUSPENDED',
        'Approved suspension failed to change canonical mapping.');
    $assert($select('tn_capital_market_states','trust_status',$org,$id)==='UNTRUSTED',
        'Approved suspension did not invalidate trusted market state.');
    $assert($select('tn_capital_market_states','quality_score',$org,$id)==='0',
        'Approved suspension retained decision-grade data quality.');
    $read=$pdo->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(state_json,'$.trust_status')) AS trust_json,
       JSON_UNQUOTE(JSON_EXTRACT(state_json,'$.market_status')) AS market_json,
       JSON_UNQUOTE(JSON_EXTRACT(state_json,'$.quality_status')) AS quality_json,
       state_version AS version_sql,
       JSON_EXTRACT(state_json,'$.state_version') AS version_json
       FROM tn_capital_market_states WHERE organization_id=:org AND venue_id=:venue AND instrument_id=:id");
    $read->execute(['org'=>$org,'venue'=>$venue,'id'=>$id]);
    $state=$read->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($state)&&$state['trust_json']==='UNTRUSTED'
        &&$state['quality_json']==='UNTRUSTED'&&$state['market_json']==='CLOSED',
        'Market State JSON still advertises stale decision-grade trust.');
    $assert((int)$state['version_sql']===(int)$state['version_json'],
        'Market State JSON state_version drifted after revocation.');
    $assert($select('tn_capital_market_subscriptions','status',$org,$id)==='DISABLED',
        'Approved suspension kept a live subscription active.');

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
    ];
    foreach($tables as $table){
        $delete=$pdo->prepare('DELETE FROM '.$table.' WHERE organization_id IN (:a,:b)');
        $delete->execute(['a'=>$org,'b'=>$other]);
    }
    $deleteOrg=$pdo->prepare('DELETE FROM cos_organizations WHERE id IN (:a,:b)');
    $deleteOrg->execute(['a'=>$org,'b'=>$other]);
    $kernel->shutdown();
}
