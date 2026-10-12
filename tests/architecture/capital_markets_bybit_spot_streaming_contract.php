<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$protocol=file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/BybitSpotTopBookProtocol.php');
$command=file_get_contents($root.'/symfony/src/Command/CapitalMarketsBybitSpotStreamingCommand.php');
$decoder=file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Bybit/BybitMarketDataDecoder.php');
$transport=file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/NativeWebSocketTransport.php');
foreach([$protocol,$command,$decoder,$transport] as $src){
    if(!is_string($src)||$src==='')throw new RuntimeException('Missing Bybit pilot artifact.');
}
foreach(['orderbook.1.','BybitOrderBookPayloadParser','bybit.spot.ws.topbook.bbo','FULL_BBO_SNAPSHOT_NOT_L2_DELTA'] as $needle){
    if(!str_contains($protocol,$needle))throw new RuntimeException('Missing top-book contract '.$needle);
}
foreach(['COS_CM_STREAMING_BYBIT_SPOT_PILOT','MarketDataStreaming','MarketDataBybit','StreamingFencedIngestionGateway','sendText('] as $needle){
    if(!str_contains($command,$needle))throw new RuntimeException('Bybit safe CLI contract missing '.$needle);
}
if(!str_contains($decoder,'wsTopBook(')||!str_contains($transport,'public function sendText(')){
    throw new RuntimeException('Bybit WebSocket top-book decoder/transport integration missing.');
}
echo "Bybit Spot BBO streaming architecture passed.\n";
