<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $s=file_get_contents($root.'/'.$path);
    if(!is_string($s)||$s==='')throw new RuntimeException('Missing Futures BBO artifact: '.$path);
    return $s;
};
$adapter=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Binance/BinanceUsdmBboMarketDataAdapter.php');
$decoder=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Binance/BinanceUsdmBboMarketDataDecoder.php');
$protocol=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/BinanceUsdmBboProtocol.php');
$command=$read('symfony/src/Command/CapitalMarketsBinanceUsdmStreamingCommand.php');
$services=$read('symfony/config/services.yaml');
foreach(['binance.usdm.perpetual.bbo.v1','/fapi/v1/ticker/bookTicker',
    'InstrumentFamily::Perpetual','MarketDataCapability::Bbo'] as $required){
    if(!str_contains($adapter,$required))throw new RuntimeException('USD-M adapter invariant missing: '.$required);
}
foreach(['binance.usdm.ticker.bbo','BinanceSpotPayloadParser','event->providerTimestamp'] as $required){
    if(!str_contains($decoder,$required))throw new RuntimeException('USD-M decoder invariant missing: '.$required);
}
foreach(['@bookTicker','USD_M','provider_update_id','family','st','ProviderTimestamp::fromMilliseconds',
    'Monotonic_BBO_NOT_L2'] as $required){
    if(!str_contains(strtolower($protocol),strtolower($required))){
        throw new RuntimeException('USD-M protocol invariant missing: '.$required);
    }
}
foreach(['COS_CM_STREAMING_BINANCE_USDM_PILOT','CryptoSpotPerpetual','MarketDataStreaming',
    'StreamingFencedIngestionGateway','NativeWebSocketTransport',
    'wss://fstream.binance.com/public/stream','BinanceUsdmBboMarketDataAdapter'] as $required){
    if(!str_contains($command,$required))throw new RuntimeException('USD-M worker safety missing: '.$required);
}
foreach(['BinanceUsdmBboMarketDataAdapter','BinanceUsdmBboMarketDataDecoder'] as $required){
    if(!str_contains($services,$required))throw new RuntimeException('USD-M registry binding missing: '.$required);
}
echo "Binance USD-M streaming architecture contracts passed.\n";
