<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value)||$value==='')throw new RuntimeException('Missing Bybit linear implementation: '.$path);
    return $value;
};
$protocol=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/BybitLinearTopBookProtocol.php');
$decoder=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Bybit/BybitPerpetualMarketDataDecoder.php');
$command=$read('symfony/src/Command/CapitalMarketsBybitLinearStreamingCommand.php');
$ci=$read('.github/workflows/ci.yml');
foreach(['bybit.linear.ws.topbook.bbo','contract_family','orderbook.1.','BybitOrderBookPayloadParser'] as $key){
    if(!str_contains($protocol,$key))throw new RuntimeException('Missing verified linear protocol: '.$key);
}
foreach(['wsTopBook(', 'MarketEventType::Bbo','Bybit linear BBO snapshot requires exactly one'] as $key){
    if(!str_contains($decoder,$key))throw new RuntimeException('Missing canonical BBO decoder: '.$key);
}
foreach(['BybitPerpetualMarketDataAdapter','InstrumentFamily::Perpetual','MarketDataStreaming',
    'CryptoSpotPerpetual','COS_CM_STREAMING_BYBIT_LINEAR_PILOT',
    'wss://stream.bybit.com/v5/public/linear','StreamingFencedIngestionGateway',
    '->runner->run('] as $key){
    if(!str_contains($command,$key))throw new RuntimeException('Missing linear worker guard: '.$key);
}
foreach(['capital_markets_bybit_linear_bbo_protocol.php','capital_markets_bybit_linear_streaming_contract.php'] as $key){
    if(!str_contains($ci,$key))throw new RuntimeException('Bybit linear tests not in mandatory CI: '.$key);
}
echo "Bybit Linear Perpetual BBO streaming contract passed.\n";
