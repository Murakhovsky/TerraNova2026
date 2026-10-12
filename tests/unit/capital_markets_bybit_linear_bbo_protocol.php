<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitOrderBookPayloadParser;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\BybitLinearTopBookProtocol;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$reject=static function(callable $callback):bool{
    try{$callback();return false;}catch(\DomainException|\InvalidArgumentException){return true;}
};
$protocol=new BybitLinearTopBookProtocol(MarketSourceId::fromString('bybit.linear'),
    VenueId::fromString('venue:bybit-linear'),'single:bybit:BTCUSDT:BBO',
    'BTCUSDT',new BybitOrderBookPayloadParser());
$frames=$protocol->subscribeFrames(['single:bybit:BTCUSDT:BBO'],5);
$assert(str_contains($frames[0],'orderbook.1.BTCUSDT'),'Incorrect Bybit top-book stream.');
$now=new DateTimeImmutable('2026-10-12T12:00:00+00:00');
$ts=((int)$now->format('U'))*1000;
$book=static fn(int $u,string $bid='65000.10'):string=>json_encode([
    'topic'=>'orderbook.1.BTCUSDT','type'=>'snapshot','ts'=>$ts,
    'data'=>['s'=>'BTCUSDT','b'=>[[$bid,'0.6']],
        'a'=>[['65000.20','0.9']],'u'=>$u,'seq'=>1000+$u],
    'cts'=>$ts,
],JSON_THROW_ON_ERROR);
$assert($reject(fn()=>$protocol->decode($book(101),$now)),'Unacknowledged Bybit quote accepted.');
$assert($protocol->decode('{"op":"subscribe","success":true,"ret_msg":"subscribe","req_id":"cm-bybit-linear-5"}',$now)['kind']==='ack',
    'Subscription ACK ignored.');
$quote=$protocol->decode($book(101),$now);
$assert($quote['kind']==='quote','Valid BBO snapshot rejected.');
$assert($quote['raw']->eventType==='bybit.linear.ws.topbook.bbo','Incorrect canonical decoder route.');
$assert($quote['raw']->transportMetadata['contract_family']==='LINEAR_PERPETUAL','Derivative source type lost.');
$assert($quote['raw']->sequence===null,'BBO must not pretend to be contiguous L2.');
$assert($quote['raw']->transportMetadata['provider_update_id']==='101','Provider update id lost.');
$assert($protocol->decode($book(101),$now)['kind']==='heartbeat','Bybit 3s idle snapshot incorrectly treated as delta.');
$assert($protocol->decode($book(101,'64999.00'),$now)['kind']==='reset','Same update id with changed book must force reset.');
$protocol->subscribeFrames(['single:bybit:BTCUSDT:BBO'],6);
$assert($reject(fn()=>$protocol->decode('{"op":"subscribe","success":true,"req_id":"cm-bybit-linear-5"}',$now)),
    'Old generation ACK accepted.');
$assert($protocol->decode('{"op":"subscribe","success":true,"req_id":"cm-bybit-linear-6"}',$now)['kind']==='ack',
    'New generation ACK rejected.');
$assert($protocol->decode($book(201),$now)['kind']==='quote','New generation did not recover.');
$assert($protocol->decode($book(1),$now)['kind']==='reset','Provider restart must revoke old book.');
$assert($protocol->decode('{"success":true,"ret_msg":"pong","op":"ping"}',$now)['kind']==='pong',
    'JSON ping/pong response not recognized.');
echo "Bybit Linear Perpetual level-one BBO streaming protocol passed.\n";
