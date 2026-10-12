<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$protocol=file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/KrakenSpotTickerProtocol.php');
$exact=file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/StreamingExactJsonNumbers.php');
$command=file_get_contents($root.'/symfony/src/Command/CapitalMarketsKrakenSpotStreamingCommand.php');
$decoder=file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Kraken/KrakenMarketDataDecoder.php');
foreach([$protocol,$exact,$command,$decoder] as $file){
    if(!is_string($file)||$file==='')throw new RuntimeException('Kraken streaming implementation missing.');
}
foreach(['event_trigger','snapshot','ticker','StreamingExactJsonNumbers::object','TIMESTAMP_MONOTONIC_BBO_NOT_L2'] as $required){
    if(!str_contains($protocol,$required))throw new RuntimeException('Kraken protocol invariant absent '.$required);
}
foreach(['COS_CM_STREAMING_KRAKEN_SPOT_PILOT','MarketDataStreaming','MarketDataKraken',
    'StreamingFencedIngestionGateway','NativeWebSocketTransport','->runner->run('] as $required){
    if(!str_contains($command,$required))throw new RuntimeException('Kraken runtime safety gate absent '.$required);
}
if(!str_contains($decoder,'websocketBbo(')||!str_contains($decoder,'kraken.spot.ws.ticker.bbo')){
    throw new RuntimeException('Kraken canonical streaming decoder not registered.');
}
echo "Kraken Spot WebSocket BBO architecture passed.\n";
