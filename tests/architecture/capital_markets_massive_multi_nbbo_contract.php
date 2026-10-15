<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $c=file_get_contents($root.'/'.$path);
    if(!is_string($c)||$c==='')throw new RuntimeException('Missing Massive multi-symbol asset '.$path);
    return $c;
};
$protocol=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MassiveStocksMultiNbboProtocol.php');
$cli=$read('symfony/src/Command/CapitalMarketsMassiveStocksBatchStreamingCommand.php');
$lease=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlStreamingWorkerLease.php');
$sweeper=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlStreamingExpiredLeaseSweeper.php');
$ci=$read('.github/workflows/ci.yml');
$trust=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/StreamingTrustInvalidator.php');
$trustContract=$read('app/Domains/CapitalMarkets/Application/Contract/StreamingInstrumentTrustInvalidatorInterface.php');
$trustIntegration=$read('tests/integration/capital_markets_instrument_stream_trust_mysql.php');
$runtimeWorkflow=$read('.github/workflows/runtime.yml');
$runtime=$read('tests/integration/capital_markets_rest_stream_writer_gate_mysql.php');
foreach(['count($keyToSymbol)<2','count($keyToSymbol)>25','StreamingExactJsonNumbers::events(',
    'subscriptionRequested','acknowledged[$symbol]','lastSequences[$symbol]',
    'staleSymbol(','ackReceivedAt[$symbol]','lastQuoteReceivedAt[$symbol]',
    'quarantineSymbol(','unsubscribeFrame(','resubscribeFrame(','recoveryArmed[$symbol]',
    'MarketDataMode::Delayed','massive.stocks.ws.nbbo','one_sided'] as $required){
    if(!str_contains($protocol,$required))throw new RuntimeException('Massive batch invariant missing '.$required);
}
foreach(['MassiveStocksMultiNbboProtocol','InstrumentFamily::Equity','ReferenceSource',
    'COS_CM_STREAMING_MASSIVE_STOCKS_PILOT','wss://socket.massive.com/stocks',
    'StreamingFencedIngestionGateway','massive-stocks-multi-nbbo',
    'StreamingInstrumentTrustInvalidatorInterface','invalidateInstrument(','MASSIVE_SYMBOL_STALE',
    "['kind']==='unsubscribed'"] as $required){
    if(!str_contains($cli,$required))throw new RuntimeException('Massive batch worker guard missing '.$required);
}
foreach(['shard_key<>:current_shard','owner_id<>\'\'','LIMIT 1 FOR UPDATE'] as $required){
    if(!str_contains($lease,$required))throw new RuntimeException('Different shard of same source can race '.$required);
}
if(!str_contains($sweeper,'massive-stocks-multi-nbbo')){
    throw new RuntimeException('No emergency sweep recovery for Massive batch.');
}
if(!str_contains($trust,'invalidateInstrument(')
    ||!str_contains($trust,'AND instrument_id=:instrument')
    ||!str_contains($trust,'if($instrumentId===null)')
    ||!str_contains($trustContract,'interface StreamingInstrumentTrustInvalidatorInterface')
    ||!str_contains($trustIntegration,'invalidateInstrument(')
    ||!str_contains($trustIntegration,'Healthy sibling equity was modified')
    ||!str_contains($runtimeWorkflow,'capital_markets_instrument_stream_trust_mysql.php')
    ||!str_contains($runtime,'batch-ws-bbo')
    ||!str_contains($ci,'capital_markets_massive_multi_nbbo_protocol.php')){
    throw new RuntimeException('Mandatory multi-symbol fencing/fixture tests missing.');
}
echo "Massive Stocks multi-symbol and source-exclusive worker contracts passed.\n";
