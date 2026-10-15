<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $s=file_get_contents($root.'/'.$path);
    if(!is_string($s)||$s==='')throw new RuntimeException('Missing Binance batch contract asset '.$path);
    return $s;
};
$p=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/BinanceSpotMultiBboProtocol.php');
$c=$read('symfony/src/Command/CapitalMarketsBinanceSpotBatchStreamingCommand.php');
$s=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlStreamingExpiredLeaseSweeper.php');
$ci=$read('.github/workflows/ci.yml');
foreach(['quarantineSymbol(','unsubscribeFrame(','resubscribeFrame(','staleSymbols(',
    'lastReceived[$symbol]','sequences[$symbol]','hardRecovery[$symbol]',
    'parser->bbo(','compareDecimal(','RawMarketEvent('] as $check){
    if(!str_contains($p,$check))throw new RuntimeException('Missing per-symbol batch guard '.$check);
}
foreach(['COS_CM_STREAMING_BINANCE_SPOT_BATCH_PILOT','StreamingInstrumentTrustInvalidatorInterface',
    'invalidateInstrument(','StreamingFencedIngestionGateway','binance-spot-multi-bbo',
    'StreamingWorkerRunner','MarketEventType::Bbo','MarketDataStreaming',
    'wss://data-stream.binance.vision/ws'] as $check){
    if(!str_contains($c,$check))throw new RuntimeException('Missing batch worker guard '.$check);
}
if(!str_contains($s,"'binance-spot-multi-bbo'")
    ||!str_contains($ci,'capital_markets_binance_spot_multi_bbo_protocol.php')
    ||!str_contains($ci,'capital_markets_binance_spot_multi_streaming_contract.php')){
    throw new RuntimeException('Binance batch missing CI or emergency recovery.');
}
echo "Binance Spot batch worker safety contracts passed.\n";
