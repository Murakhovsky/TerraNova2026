<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance\BinanceSpotPayloadParser;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\BinanceSpotBboProtocol;

require dirname(__DIR__,2).'/vendor/autoload.php';
$assert=static function(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);};
$reject=static function(callable $fn):bool{
    try{$fn();return false;}catch(\DomainException|\InvalidArgumentException){return true;}
};
$p=new BinanceSpotBboProtocol(
    MarketSourceId::fromString('binance.spot'),VenueId::fromString('venue:binance'),
    'source|venue|instrument|BBO','BTCUSDT',new BinanceSpotPayloadParser(),
);
$subscribe=$p->subscribeFrames(['source|venue|instrument|BBO'],3);
$assert(str_contains($subscribe[0],'btcusdt@bookTicker'),'Binance stream name incorrect.');
$frame='{"u":400900217,"s":"BTCUSDT","b":"65000.10","B":"1.5","a":"65000.20","A":"3.2"}';
$now=new DateTimeImmutable('2026-10-12T12:00:00+00:00');
$assert($reject(fn()=>$p->decode($frame,$now)),'Cannot receive BBO before ACK.');
$assert($p->decode('{"result":null,"id":3}',$now)['kind']==='ack','ACK failed.');
$quote=$p->decode($frame,$now);
$assert($quote['kind']==='quote','Quote rejected.');
$assert($quote['raw']->sequence===null,'BBO update ID must not masquerade as contiguous L2 sequence.');
$assert($quote['raw']->transportMetadata['provider_update_id']==='400900217','Update ID evidence lost.');
$assert($quote['raw']->transportMetadata['transport']==='WEBSOCKET','Stream provenance lost.');
$assert($reject(fn()=>$p->decode($frame,$now)),'Duplicate update should fail closed.');
$assert($reject(fn()=>$p->decode(str_replace('"s":"BTCUSDT"','"s":"ETHUSDT"',$frame),$now)),'Wrong symbol accepted.');
$assert($reject(fn()=>$p->decode('{"result":null,"id":999}',$now)),'Unexpected ACK accepted.');
echo "Binance Spot BBO protocol passed.\n";
