<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$cmd=file_get_contents($root.'/symfony/src/Command/CapitalMarketsBinanceSpotStreamingCommand.php');
$gateway=file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/StreamingFencedIngestionGateway.php');
$protocol=file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/BinanceSpotBboProtocol.php');
$services=file_get_contents($root.'/symfony/config/services.yaml');
foreach([$cmd,$gateway,$protocol,$services] as $file){
    if(!is_string($file)||$file==='')throw new RuntimeException('Binance streaming artifact missing.');
}
foreach(['MarketDataStreaming','COS_CM_STREAMING_BINANCE_SPOT_PILOT','StreamingFencedIngestionGateway','NativeWebSocketTransport','->runner->run('] as $required){
    if(!str_contains($cmd,$required))throw new RuntimeException('Pilot command safety missing: '.$required);
}
foreach(['FOR UPDATE','->transactional(','->ingestion->ingest(','MarketConnectionState::Active'] as $required){
    if(!str_contains($gateway,$required))throw new RuntimeException('Fenced streaming ingestion missing: '.$required);
}
foreach(['binance.spot.ticker.bbo','raw_websocket_json','provider_update_id','@bookTicker'] as $required){
    if(!str_contains($protocol,$required))throw new RuntimeException('Binance protocol missing: '.$required);
}
if(!str_contains($services,'StreamingFencedIngestionGateway:'))throw new RuntimeException('DI configuration missing.');
echo "Binance Spot pilot architecture passed.\n";
