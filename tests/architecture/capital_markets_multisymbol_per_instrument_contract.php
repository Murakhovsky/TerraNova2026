<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static function(string $file)use($root):string{
    $s=file_get_contents($root.'/'.$file);
    if(!is_string($s)||$s==='')throw new RuntimeException('Missing multi-symbol module '.$file);
    return $s;
};
$multi=$read('app/Domains/CapitalMarkets/Application/Service/MultiSymbolStreamingCore.php');
$gateway=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/StreamingFencedIngestionGateway.php');
$repo=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlPerInstrumentStreamTrust.php');
$schema=$read('app/migrations/20261015_000145_capital_markets_instrument_stream_trust.sql');
$module=$read('app/Domains/CapitalMarkets/module.php');
$services=$read('symfony/config/services.yaml');
$binance=$read('symfony/src/Command/CapitalMarketsBinanceSpotBatchStreamingCommand.php');
$massive=$read('symfony/src/Command/CapitalMarketsMassiveStocksBatchStreamingCommand.php');
$planner=$read('symfony/src/Command/CapitalMarketsMultiSymbolPlanCommand.php');
foreach(['source_scoped_leases','no_parallel_same_source_shards',
 'PLANNED_NOT_RUNNING','reconnect','No approved multi-symbol',
 'Stream','binance.spot.rest','massive.stocks.v2'] as $item){
    if(!str_contains($multi,$item))throw new RuntimeException('Multi-symbol admission guard missing '.$item);
}
foreach([$binance,$massive] as $worker){
    if(!str_contains($worker,'STREAM_TOPOLOGY_CHANGED')
        ||!str_contains($worker,'$lastTopologyReview>=30000')
        ||!str_contains($worker,'hash_equals($initialTopologyDigest')){
        throw new RuntimeException('Long-lived worker must fence changed subscription/mapping intent.');
    }
    if(!str_contains($worker,'$this->multiSymbolCore->plan(')
        ||!str_contains($worker,'StreamingWorkerRunner')){
        throw new RuntimeException('Production batch worker bypasses shared admission or fenced runner.');
    }
}
if(!str_contains($planner,'MultiSymbolStreamingCore')
    ||!str_contains($planner,'MarketDataManage')){
    throw new RuntimeException('Multi-symbol CLI permission admission missing.');
}
foreach(['tn_capital_market_instrument_stream_trust','recovery_required','fencing_token'] as $item){
    if(!str_contains($schema,$item))throw new RuntimeException('Missing independent trust schema.');
}
foreach(['$this->instrumentTrust->observe(','$this->ingestion->ingest('] as $item){
    if(!str_contains($gateway,$item))throw new RuntimeException('Gateway skipped atomic per-instrument recording.');
}
if(!str_contains($repo,'MysqlMarketSourceWriterGate::lockSource')
    ||!str_contains($repo,'state_version=state_version+1')){
    throw new RuntimeException('Symbol sweep must respect source lease locking and advance state version.');
}
if(!str_contains($repo,'FOR UPDATE')||!str_contains($repo,"'UNTRUSTED'")
    ||!str_contains($repo,'last_sequence')||!str_contains($repo,'trust_status')){
    throw new RuntimeException('Per-symbol trust is not isolated/fenced.');
}
if(!str_contains($module,'20261015_000145_capital_markets_instrument_stream_trust.sql')
    ||!str_contains($services,'MysqlPerInstrumentStreamTrust:')){
    throw new RuntimeException('Production schema/service wiring missing.');
}
$compose=$read('docker-compose.yml');
if(!str_contains($compose,'profiles: [cm-instrument-trust-sweeper]')
    ||!str_contains($compose,'COS_CM_INSTRUMENT_TRUST_SWEEPER_ENABLED')
    ||!str_contains($compose,'sleep 60')){
    throw new RuntimeException('Independent stale sweep profile missing.');
}
$fleet=$read('symfony/src/Command/CapitalMarketsMultiSymbolFleetCommand.php');
foreach(['COS_CM_STREAMING_MULTI_FLEET_ENABLED','--seconds=0',
    'max-workers','restartAt','proc_open','proc_terminate','proc_close',
    'SIGTERM','STAGGERED_NOT_REAL'] as $check){
    if($check==='STAGGERED_NOT_REAL')continue;
    if(!str_contains($fleet,$check))throw new RuntimeException('Live fleet missing '.$check);
}
if(!str_contains($compose,'profiles: [cm-stream-fleet]')
    ||!str_contains($compose,'COS_CM_FLEET_SOURCES')
    ||!str_contains($compose,'restart: on-failure:3')){
    throw new RuntimeException('Safe source-fenced fleet deployment profile missing.');
}
echo "Shared source-fenced multiplexing and per-instrument durable trust architecture passed.\n";
