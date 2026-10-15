<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\InstrumentPoolProductionSoakPolicy;
require dirname(__DIR__,2).'/vendor/autoload.php';
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$policy=new InstrumentPoolProductionSoakPolicy();
$rows=[];
foreach(['BBO'=>250,'L2'=>50] as $kind=>$total){
    for($i=0;$i<$total;$i++){
        $rows[]=['kind'=>$kind,'source_id'=>'live-source',
            'instrument_id'=>$kind.'-'.$i,'samples'=>288,
            'hour_buckets'=>24,'five_minute_buckets'=>288,'bad_samples'=>0,'age_seconds'=>10,
            'mode'=>'LIVE','approved_entitlement'=>true,
            'issuer_verified'=>true,'source_enabled'=>true,'worker_active'=>true,
            'source_healthy'=>true,'subscription_active'=>true,'unresolved_gap'=>false];
    }
}
$good=$policy->evaluate($rows,true);
$assert($good['accepted']===true&&$good['verified']['BBO']===250
    &&$good['verified']['L2']===50,'Exact full capacity must pass only with independent LIVE evidence.');
$assert($policy->evaluate($rows,false)['accepted']===false,
    'Synthetic evidence cannot bypass elapsed 24h time.');
$bad=$rows;$bad[0]['hour_buckets']=23;
$assert($policy->evaluate($bad,true)['accepted']===false,
    '23 of 24 hourly buckets must fail release.');
$bad=$rows;$bad[0]['five_minute_buckets']=275;
$assert(!$policy->evaluate($bad,true)['accepted'],
    'Sparse once-per-hour stream must not pass continuous 24h soak.');
$bad=$rows;$bad[0]['bad_samples']=1;
$assert($policy->evaluate($bad,true)['accepted']===false,
    'Quality/recovery defects must fail release.');
$bad=$rows;$bad[0]['approved_entitlement']=false;
$assert(!$policy->evaluate($bad,true)['accepted'],'Missing external entitlement passed.');
$bad=$rows;$bad[0]['issuer_verified']=false;
$assert(!$policy->evaluate($bad,true)['accepted'],'Unverified token issuer passed.');
$bad=$rows;$bad[0]['source_healthy']=false;
$assert(!$policy->evaluate($bad,true)['accepted'],'Degraded health passed.');
$bad=$rows;$bad[0]['subscription_active']=false;
$assert(!$policy->evaluate($bad,true)['accepted'],'Inactive subscription passed.');
$bad=$rows;$bad[0]['unresolved_gap']=true;
$assert(!$policy->evaluate($bad,true)['accepted'],'Unresolved data gap passed.');
$bad=$rows;$bad[0]['worker_active']=false;
$assert(!$policy->evaluate($bad,true)['accepted'],'Unleased market writer passed.');
$bad=$rows;$bad[0]['age_seconds']=301;
$assert(!$policy->evaluate($bad,true)['accepted'],'Stale market stream passed.');
$bad=$rows;$bad[0]['mode']='DELAYED';
$assert(!$policy->evaluate($bad,true)['accepted'],'Delayed prices were accepted as LIVE.');
$bad=$rows;array_pop($bad);
$assert(!$policy->evaluate($bad,true)['accepted'],'49 rather than 50 L2 workers passed.');
$bad=$rows;$bad[]=end($rows);
try{$policy->evaluate($bad,true);throw new RuntimeException('Duplicated symbol passed.');}
catch(DomainException $e){}
$assert(!$policy->evaluate([],true)['accepted'],'Empty production evidence passed.');
echo "Strict 250 BBO / 50 L2 fail-closed live soak policy passed; fixtures NOT accepted as actual soak.\n";
