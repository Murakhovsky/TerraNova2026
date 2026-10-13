<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$load=static function(string $path)use($root):string{
    $text=file_get_contents($root.'/'.$path);
    if(!is_string($text)||$text==='')throw new RuntimeException('Missing Massive WS file: '.$path);
    return $text;
};
$protocol=$load('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MassiveStocksQuoteProtocol.php');
$decoder=$load('app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Massive/MassiveMarketDataDecoder.php');
$numbers=$load('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/StreamingExactJsonNumbers.php');
$command=$load('symfony/src/Command/CapitalMarketsMassiveStocksStreamingCommand.php');
$sweeper=$load('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlStreamingExpiredLeaseSweeper.php');
$ci=$load('.github/workflows/ci.yml');
foreach(['auth_success','subscribed to: Q.','one_sided','StreamingExactJsonNumbers::events',
    'MarketDataMode::Delayed','massive.stocks.ws.nbbo','ReferenceType'] as $required){
    if(!str_contains($protocol,$required) && $required!=='ReferenceType'){
        throw new RuntimeException('Massive provider protocol missing '.$required);
    }
}
foreach(['streamingQuote(','ReferenceType::Nbbo','MarketDataMode'] as $required){
    if($required!=='MarketDataMode'&&!str_contains($decoder,$required)){
        throw new RuntimeException('Massive canonical NBBO decoder missing '.$required);
    }
}
foreach(['COS_CM_STREAMING_MASSIVE_STOCKS_PILOT','MarketDataMassive',
    'MarketDataStreaming','TokenizedEquity','MarketSourceCredentialResolver',
    'ReferenceSource','TradingSource','MASSIVE_STOCKS_WS_Q_LIVE',
    'MASSIVE_STOCKS_WS_Q_DELAYED','wss://socket.massive.com/stocks',
    'wss://delayed.massive.com/stocks','StreamingFencedIngestionGateway','sendPing(','kind===\'PONG\''] as $required){
    if(!str_contains($command,$required))throw new RuntimeException('Massive source guard missing '.$required);
}
if(!str_contains($sweeper,'massive-stocks-single-bbo')
    ||!str_contains($numbers,'public static function events(')
    ||!str_contains($ci,'capital_markets_massive_stocks_ws_protocol.php')){
    throw new RuntimeException('Massive source recovery/CI coverage missing.');
}
echo "Massive stock websocket canonical integration contract passed.\n";
