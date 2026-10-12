<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance\BinanceSpotPayloadParser;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\BinanceUsdmBboProtocol;

require dirname(__DIR__,2).'/vendor/autoload.php';

$ok=static function(bool $condition,string $reason):void{
    if(!$condition)throw new RuntimeException($reason);
};
$reject=static function(callable $f):bool{
    try{$f();return false;}catch(\DomainException|\InvalidArgumentException){return true;}
};
$proto=new BinanceUsdmBboProtocol(
    MarketSourceId::fromString('binance.usdm'),
    VenueId::fromString('venue:binance-usdm'),'src|BTCUSDT|BBO',
    'BTCUSDT',new BinanceSpotPayloadParser(),
);
$frames=$proto->subscribeFrames(['src|BTCUSDT|BBO'],5);
$frame=json_decode($frames[0],true,32,JSON_THROW_ON_ERROR);
$ok(($frame['params'][0]??null)==='btcusdt@bookTicker','Wrong Binance USD-M channel.');
$now=new DateTimeImmutable('2026-10-12T12:00:00+00:00');
$millis=$now->getTimestamp()*1000;
$payload=static fn(int $u,?int $st=1,string $symbol='BTCUSDT',string $bid='65000.123456789123'):
    string=>json_encode([
        'e'=>'bookTicker','E'=>$millis,'T'=>$millis-1,'u'=>$u,
        's'=>$symbol,'st'=>$st,'b'=>$bid,'B'=>'1.01','a'=>'65001.00','A'=>'0.5',
    ],JSON_THROW_ON_ERROR);
$ok($reject(fn()=>$proto->decode($payload(101),$now)),'Pre-ACK data should be rejected.');
$ok($proto->decode('{"result":null,"id":5}',$now)['kind']==='ack','Subscribe ACK rejected.');
$quote=$proto->decode($payload(101),$now);
$ok($quote['kind']==='quote','USD-M event not accepted.');
$ok($quote['raw']->eventType==='binance.usdm.ticker.bbo','Futures/spot canonical route conflated.');
$ok($quote['raw']->sequence===null,'BBO IDs cannot stand in for L2 continuity.');
$ok($quote['raw']->transportMetadata['provider_update_id']==='101','Futures BBO update ID missing.');
$ok($quote['raw']->transportMetadata['contract_family']==='USD_M','USD-M provenance missing.');
$ok($quote['raw']->providerTimestamp?->format('U')===$now->format('U'),'Provider timestamp lost.');
$normalized=json_decode($quote['raw']->rawPayload['raw_json'],true,32,JSON_THROW_ON_ERROR);
$ok($normalized['bidPrice']==='65000.123456789123','BBO decimals damaged.');
$ok($reject(fn()=>$proto->decode($payload(101),$now)),'Duplicate BBO update not fenced.');
$ok($reject(fn()=>$proto->decode($payload(102,2),$now)),'COIN-M event accepted on USD-M source.');
$ok($reject(fn()=>$proto->decode($payload(102,null),$now)),'Missing family accepted.');
$ok($reject(fn()=>$proto->decode($payload(103,1,'ETHUSDT'),$now)),'Other future symbol accepted.');
$ok($reject(fn()=>$proto->decode($payload(104,1,'BTCUSDT','not-a-price'),$now)),
    'Invalid future bid accepted.');
$ok($reject(fn()=>$proto->decode($payload(100),$now)),'Out-of-order futures update accepted.');
$proto->subscribeFrames(['src|BTCUSDT|BBO'],6);
$ok($reject(fn()=>$proto->decode('{"result":null,"id":5}',$now)),
    'Prior generation Binance Futures ACK accepted.');
$ok($proto->decode('{"result":null,"id":6}',$now)['kind']==='ack','New generation ACK failed.');
$ok($proto->decode($payload(10),$now)['kind']==='quote','Reconnected generation never recovered.');
$stale=new DateTimeImmutable('2026-10-12T12:10:00+00:00');
$ok($reject(fn()=>$proto->decode($payload(11),$stale)),'Stale futures timestamp accepted.');
echo "Binance USD-M Futures BBO streaming protocol passed.\n";
