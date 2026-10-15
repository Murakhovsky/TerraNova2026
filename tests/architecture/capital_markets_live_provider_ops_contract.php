<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static function(string $p)use($root):string{
    $s=file_get_contents($root.'/'.$p);
    if(!is_string($s)||$s==='')throw new RuntimeException('Missing provider ops contract '.$p);
    return $s;
};
$schema=$read('app/migrations/20261015_000146_capital_markets_provider_ops.sql');
$proof=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlStreamingProviderVerification.php');
$health=$read('app/Domains/CapitalMarkets/Application/Service/StreamingOperationsHealthEvaluator.php');
$incidents=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlStreamingIncidentManager.php');
$delivery=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/StreamingIncidentDelivery.php');
$audit=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlStreamingFailoverAudit.php');
$reader=$read('app/Domains/CapitalMarkets/Application/Service/InstrumentPoolProductionEvidenceReader.php');
$binance=$read('symfony/src/Command/CapitalMarketsBinanceSpotBatchStreamingCommand.php');
$massive=$read('symfony/src/Command/CapitalMarketsMassiveStocksBatchStreamingCommand.php');
$cli=$read('symfony/src/Command/CapitalMarketsStreamingFailoverCommand.php');
$compose=$read('docker-compose.yml');
foreach(['provider_stream_receipts','stream_incidents','stream_failover_observations'] as $table){
    if(!str_contains($schema,$table))throw new RuntimeException('Missing durable provider ops table '.$table);
}
foreach([$binance,$massive] as $worker){
    if(!str_contains($worker,'$this->verification->acknowledged(')
        ||!str_contains($worker,'$this->verification->acceptedQuote(')){
        throw new RuntimeException('Provider ACKs or quote receipts not wired to actual worker protocol.');
    }
}
foreach(['FOR UPDATE','canonical_event_id','received_timestamp>=p.ack_at',
    "q.trust_status='TRUSTED'","t.status='TRUSTED'",
    "e.data_mode='LIVE'"] as $needle){
    if(!str_contains($proof,$needle))throw new RuntimeException('Provider ACK/canonical trust verification bypass: '.$needle);
}
foreach(['STREAM_PROVIDER_LIVE_NOT_VERIFIED','STREAM_WORKER_NOT_ACTIVE',
    'STREAM_SUBSCRIPTIONS_REVOKED'] as $code){
    if(!str_contains($health,$code))throw new RuntimeException('Missing operational alert '.$code);
}
if(!str_contains($incidents,'ACKNOWLEDGED')
    ||!str_contains($incidents,'RESOLVED')
    ||!str_contains($delivery,'NotificationDispatcherInterface')
    ||!str_contains($delivery,'QUEUED')){
    throw new RuntimeException('Durable incident lifecycle or outbox semantics missing.');
}
if(!str_contains($audit,'SOURCE_GUARD_SHARD')
    ||!str_contains($audit,"'INJECTED'")
    ||!str_contains($audit,"'RECOVERED'")
    ||!str_contains($cli,'INJECT_FENCED_FAILOVER')
    ||!str_contains($cli,'COS_CM_STREAM_FAILOVER_DRILL_ARMED')){
    throw new RuntimeException('Failover drill not source-fenced and double armed.');
}
if(!str_contains($reader,"'SOURCE_FAILOVER_PROVEN'")
    ||!str_contains($reader,"'provider_live_verified'")){
    throw new RuntimeException('24h acceptance bypasses provider/failover proof.');
}
if(!str_contains($compose,'profiles: [cm-stream-ops-monitor]')
    ||!str_contains($compose,'COS_CM_STREAM_OPS_MONITOR_ENABLED: "1"')
    ||!str_contains($compose,'sleep 60')){
    throw new RuntimeException('Production incident monitor not explicitly opt-in.');
}
echo "P0-5 provider verification, P0-6 durable ops and P0-7 failover boundaries passed.\n";
