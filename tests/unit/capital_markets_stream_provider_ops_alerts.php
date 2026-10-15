<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Domains\CapitalMarkets\Application\Service\StreamingOperationsHealthEvaluator;
$assert=static function(bool $ok,string $label):void{
    if(!$ok)throw new RuntimeException($label);
};
$eval=new StreamingOperationsHealthEvaluator();
$base=['leases'=>[
    ['source_id'=>'binance','shard_key'=>'stream','lease_state'=>'ACTIVE'],
],'quality_by_source'=>[],
'provider_by_source'=>['binance'=>['requested_bbo'=>100,'confirmed_live'=>100,
    'revoked'=>0,'receipts'=>100]]];
$ok=$eval->evaluate($base);
$assert($ok['health']==='OK'&&$ok['alerts']===[],'Healthy 100/100 ACKs raised false positive.');
$bad=$base;$bad['provider_by_source']['binance']['confirmed_live']=99;
$problem=$eval->evaluate($bad);
$assert($problem['health']==='CRITICAL'
    &&$problem['alerts'][0]['code']==='STREAM_PROVIDER_LIVE_NOT_VERIFIED'
    &&$problem['alerts'][0]['count']===1,
    'One missing provider ACK was hidden by 99 healthy siblings.');
$noWorker=$bad;$noWorker['leases'][0]['lease_state']='RELEASED';
$missing=$eval->evaluate($noWorker);
$assert($missing['alerts'][0]['code']==='STREAM_WORKER_NOT_ACTIVE',
    'Released worker falsely passed source readiness.');
$revoked=$base;$revoked['provider_by_source']['binance']['revoked']=1;
$warn=$eval->evaluate($revoked);
$assert($warn['health']==='WARNING'
    &&$warn['alerts'][0]['code']==='STREAM_SUBSCRIPTIONS_REVOKED',
    'Provider subscription revocation was not observable.');
try{$eval->evaluate(['leases'=>[],'quality_by_source'=>[],
    'provider_by_source'=>['binance'=>['requested_bbo'=>-1,
        'confirmed_live'=>1,'revoked'=>0]]]);
    throw new RuntimeException('Negative provider metrics accepted.');
}catch(DomainException $expected){}
$rate=$base;
$rate['source_health']['binance']=[
 'rate_limit_state'=>'THROTTLED','clock_reliable'=>true,'queue_lag'=>201];
$alarm=$eval->evaluate($rate);
$codes=array_column($alarm['alerts'],'code');
$assert(in_array('STREAM_PROVIDER_RATE_LIMIT',$codes,true)
    &&in_array('STREAM_QUEUE_LAG',$codes,true),
    'Rate-limit and backpressure alerts missing.');
$skew=$base;$skew['source_health']['binance']=[
 'rate_limit_state'=>'OK','clock_reliable'=>false,'queue_lag'=>0];
$assert(in_array('STREAM_SOURCE_CLOCK_UNRELIABLE',
    array_column($eval->evaluate($skew)['alerts'],'code'),true),
    'Unreliable source clock incorrectly counted as healthy.');
echo "Source-scoped provider ACK, partial symbol outage, revoked subscription and worker alerts passed.\n";
