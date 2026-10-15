<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static function(string $p)use($root):string{
    $s=file_get_contents($root.'/'.$p);
    if(!is_string($s)||$s==='')throw new RuntimeException('Missing P1 architecture contract '.$p);
    return $s;
};
$bridge=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2SourceFencedSession.php');
$cmd=$read('symfony/src/Command/CapitalMarketsL2StreamCommand.php');
$pub=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2IngestionPublisher.php');
foreach(['->acquire(','->renew(','->invalidate(','->release(','->ingest('] as $part){
    if(!str_contains($bridge,$part))throw new RuntimeException('L2 recovery not source-fenced: '.$part);
}
if(!str_contains($cmd,'COS_CM_L2_FENCED_ENABLED')
    ||!str_contains($cmd,'L2SourceFencedSession')
    ||!str_contains($pub,'$fencedWriter')){
    throw new RuntimeException('L2 worker bypasses optional common source-fenced writer.');
}
foreach(['BinanceL2RecoveryCoordinator.php','BybitL2RecoveryCoordinator.php',
    'KrakenL2BookRecovery.php'] as $name){
    $s=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/'.$name);
    if(!str_contains($s,'snapshot')
        ||(!str_contains($s,'sequence')&&!str_contains($s,'Sequence')
            &&!str_contains($s,'Checksum')&&!str_contains($s,'checksum'))){
        throw new RuntimeException('Venue-specific snapshot/sequence recovery missing: '.$name);
    }
}
$proto=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/BybitLinearDerivativesProtocol.php');
$worker=$read('symfony/src/Command/CapitalMarketsBybitDerivativesStreamingCommand.php');
$life=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlDerivativeLifecycleRegistry.php');
$trust=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlDerivativeStreamTrust.php');
foreach(['tickers.','snapshot','delta','nextFundingTime','subscription ACK',
    'ProviderTimestamp','MarketSourceId','RawMarketEvent'] as $part){
    if(!str_contains($proto,$part))throw new RuntimeException('Derivative feed lacks '.$part);
}
foreach(['COS_CM_STREAMING_BYBIT_DERIVATIVES_ENABLED',
    'assertTrading(','->record(','->ingest(','->run('] as $part){
    if(!str_contains($worker,$part))throw new RuntimeException('Derivatives worker bypasses '.$part);
}
foreach(['SELECT 1 FROM tn_capital_market_canonical_events','FOR UPDATE',
    "q.trust_status='TRUSTED'","sub.status='ACTIVE'"] as $part){
    if(!str_contains($trust,$part))throw new RuntimeException('Derivative canonical trust bypass '.$part);
}
if(!str_contains($life,"'Trading'")||!str_contains($life,'derivative_contract_transitions')){
    throw new RuntimeException('Derivative lifecycle transitions not authoritative.');
}
echo "Fenced L2 recovery and canonical Bybit derivatives streaming P1 contract passed.\n";
