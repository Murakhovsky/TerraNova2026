<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\BybitLinearDerivativesProtocol;
$assert=static function(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
};
$keys=['FUNDING_RATE','MARK_PRICE','INDEX_PRICE','OPEN_INTEREST'];
$protocol=new BybitLinearDerivativesProtocol(
    MarketSourceId::fromString('ci-derivatives'),
    VenueId::fromString('ci-bybit'), 'BTCUSDT', $keys,28800,'0.01','-0.01',
);
$received=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$ms=(string)($received->getTimestamp()*1000);
$raw=static fn(array $row):string=>json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
$frame=static fn(string $type,array $data,string $stamp):string=>$raw([
    'topic'=>'tickers.BTCUSDT','type'=>$type,'ts'=>$stamp,'data'=>$data,
]);
$data=['symbol'=>'BTCUSDT','markPrice'=>'67500.00010000',
    'indexPrice'=>'67499.00010000','openInterest'=>'0',
    'fundingRate'=>'-0.00012000','nextFundingTime'=>(string)(($received->getTimestamp()+28000)*1000)];
$snapshot=$frame('snapshot',$data,$ms);
try{$protocol->decode($snapshot,$received);throw new RuntimeException('Data before ACK accepted.');}
catch(DomainException $expected){}
$protocol->subscribeFrames($keys,1);
try{$protocol->decode($frame('delta',['symbol'=>'BTCUSDT','markPrice'=>'67600'],$ms),$received);
    throw new RuntimeException('Delta before ACK accepted.');}
catch(DomainException $expected){}
$assert($protocol->decode($raw(['op'=>'subscribe','req_id'=>'cm-derivatives-1','success'=>true]),$received)['kind']==='ack',
    'Provider subscription was not verified.');
try{$protocol->decode($frame('delta',['symbol'=>'BTCUSDT','markPrice'=>'67600'],$ms),$received);
    throw new RuntimeException('Delta before initial snapshot accepted.');}
catch(DomainException $expected){}
$parsed=$protocol->decode($snapshot,$received);
$assert($parsed['kind']==='snapshot'&&count($parsed['events'])===4,
    'Initial provider snapshot must deliver four independent capabilities.');
$types=array_map(static fn($event)=>$event->eventType,$parsed['events']);
$assert(count(array_unique($types))===4,'Derivative capabilities conflated.');
$funding=array_values(array_filter($parsed['events'],
    static fn($event)=>$event->eventType==='bybit.linear.ws.derivative.funding'));
$assert($funding[0]->rawPayload['value']==='-0.00012000'
    &&$funding[0]->rawPayload['funding_interval_seconds']===28800,
    'Decimal funding normalization lost or hardcoded interval.');
// Test the actual canonical market decoder, not merely WebSocket parsing.
$marketSource=new \Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor(
    MarketSourceId::fromString('ci-derivatives'),VenueId::fromString('ci-bybit'),
    'bybit.linear-perpetual.v5',true,10,
    [\Domains\CapitalMarkets\Domain\MarketData\MarketSourceRole::TradingSource],
    null,
    new \Domains\CapitalMarkets\Domain\MarketData\RateLimitPolicy(100,60),
    new \Domains\CapitalMarkets\Domain\MarketData\ReconnectPolicy(),
    new \Domains\CapitalMarkets\Domain\MarketData\MarketHealthPolicy(),
);
$decoder=new \Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitPerpetualMarketDataDecoder(
    new \Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitPerpetualTickerPayloadParser(),
    new \Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitPerpetualInstrumentPayloadParser(),
    new \Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitOrderBookPayloadParser()
);
foreach($parsed['events'] as $rawEvent){
    $decoded=$decoder->decode($marketSource,$rawEvent);
    $assert($decoded->externalInstrument==='BTCUSDT'
        &&$decoded->values['value']===$rawEvent->rawPayload['value'],
        'Canonical derivative event dropped precise provider scalar.');
    if($decoded->eventType->value==='FUNDING_RATE'){
        $assert($decoded->values['funding_interval_seconds']===28800
            &&$decoded->values['rate_type']==='NORMALIZED_LONGS_PAY_SHORTS',
            'Normalized WS funding missing settlement period.');
    }
}
$delta=$protocol->decode($frame('delta',['symbol'=>'BTCUSDT','markPrice'=>'67501.0000001'],
    (string)((int)$ms+1000)),$received);
$assert($delta['kind']==='delta'&&count($delta['events'])===1
    &&$delta['events'][0]->rawPayload['value']==='67501.0000001',
    'Partial delta was incorrectly reinterpreted as four fresh field observations.');
$invalid=[
    $frame('delta',['symbol'=>'ETHUSDT','indexPrice'=>'1'],(string)((int)$ms+2000)),
    $frame('delta',['symbol'=>'BTCUSDT','markPrice'=>0.00001],(string)((int)$ms+2000)),
    $frame('delta',['symbol'=>'BTCUSDT','markPrice'=>'-10'],(string)((int)$ms+2000)),
    $frame('delta',['symbol'=>'BTCUSDT','markPrice'=>'1'],(string)((int)$ms-5000)),
    $raw(['topic'=>'tickers.WRONG','type'=>'snapshot','ts'=>$ms,'data'=>$data]),
];
foreach($invalid as $frame){
    try{$protocol->decode($frame,$received);throw new RuntimeException('Corrupt/foreign derivative frame accepted.');}
    catch(DomainException $expected){}
}
$protocol->subscribeFrames($keys,2);
try{$protocol->decode($snapshot,$received);throw new RuntimeException('Trust leaked into reconnect epoch.');}
catch(DomainException $expected){}
echo "Bybit derivative streaming ACK, full snapshot, partial delta, exact decimals, isolation and recovery passed.\n";
