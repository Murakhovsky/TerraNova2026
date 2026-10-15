<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MassiveStocksQuoteProtocol;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\StreamingExactJsonNumbers;

require dirname(__DIR__,2).'/vendor/autoload.php';
$assert=static function(bool $ok,string $reason):void{
    if(!$ok)throw new RuntimeException($reason);
};
$reject=static function(callable $f):bool{
    try{$f();return false;}catch(\DomainException|\InvalidArgumentException){return true;}
};
$now=new DateTimeImmutable('2026-10-13T12:00:00+00:00');
$ms=(string)($now->getTimestamp()*1000-1000);
$numericBid='205.123456789012345678901234';
$frame=static function(int $seq,string $timestamp,string $bid='205.123456789012345678901234',
    string $extra=''):string{
    return '[{"ev":"Q","sym":"AAPL","bp":'.$bid.',"bs":100,"ap":206.125,"as":200,'.
        '"t":'.$timestamp.',"q":'.$seq.$extra.'}]';
};
$proto=new MassiveStocksQuoteProtocol(
    MarketSourceId::fromString('massive.stocks'),'ref|AAPL|BBO','AAPL',
    MarketDataMode::Live,'test-vault-key',
);
$auth=$proto->subscribeFrames(['ref|AAPL|BBO'],1);
$assert(str_contains($auth[0],'"action":"auth"'),'Massive connection must AUTH first.');
$assert(!str_contains($auth[0],'"action":"subscribe"'),'Subscription sent before auth.');
$assert($reject(fn()=>$proto->subscriptionFrame()),'Subscribe allowed before auth_success.');
$assert($reject(fn()=>$proto->decodeBatch($frame(100,$ms),$now)),
    'Pre-ACK Massive quotes accepted.');
$assert($proto->decodeBatch('[{"ev":"status","status":"connected"}]',$now)[0]['kind']==='connected',
    'Massive connection status not recognized.');
$assert($proto->decodeBatch('[{"ev":"status","status":"auth_success"}]',$now)[0]['kind']==='auth',
    'Massive auth_success was not recognized.');
$subscribe=$proto->subscriptionFrame();
$assert(str_contains($subscribe,'"params":"Q.AAPL"'),'Massive quote topic was not Q.AAPL.');
$assert($reject(fn()=>$proto->decodeBatch('[{"ev":"status","status":"success","message":"subscribed to: Q.MSFT"}]',$now)),
    'Unexpected subscribed ticker accepted.');
$proto->subscribeFrames(['ref|AAPL|BBO'],2);
$proto->decodeBatch('[{"ev":"status","status":"auth_success"}]',$now);
$proto->subscriptionFrame();
$ack=$proto->decodeBatch('[{"ev":"status","status":"success","message":"subscribed to: Q.AAPL"}]',$now);
$assert($ack[0]['kind']==='ack','Provider subscription ACK not accepted.');
$parsed=StreamingExactJsonNumbers::events($frame(100,$ms));
$assert($parsed[0]['bp']===$numericBid,'Float conversion lost Massive NBBO price precision.');
$quote=$proto->decodeBatch($frame(100,$ms),$now);
$assert($quote[0]['kind']==='quote','Valid Massive NBBO quote rejected.');
$assert($quote[0]['raw']->eventType==='massive.stocks.ws.nbbo',
    'Massive canonical decoder route mismatch.');
$assert($quote[0]['raw']->venueId===null,'Massive parent equity must not be a trading venue.');
$assert($quote[0]['raw']->sequence==='100','Non-contiguous sequence evidence discarded.');
$assert($quote[0]['raw']->mode===MarketDataMode::Live,'LIVE feed mode lost.');
$normalized=json_decode($quote[0]['raw']->rawPayload['raw_json'],true,32,JSON_THROW_ON_ERROR);
$assert($normalized['bid_price']===$numericBid,'Normalized price lost precision.');
$assert(!str_contains(json_encode($quote[0]['raw']->rawPayload,JSON_THROW_ON_ERROR),
    'test-vault-key'),'Authentication secret leaked into persisted event.');
$assert($proto->decodeBatch($frame(100,$ms),$now)[0]['kind']==='heartbeat',
    'Duplicate NBBO sequence should be liveness only.');
$assert($proto->decodeBatch($frame(101,$ms),$now)[0]['kind']==='quote',
    'Non-contiguous Massive quote sequence rejected.');
$assert($proto->decodeBatch($frame(99,$ms),$now)[0]['kind']==='reset',
    'Intra-session sequence rollback should force recovery.');
$assert($proto->decodeBatch('[{"ev":"Q","sym":"AAPL","bp":205.2,"bs":1,"t":'.$ms.',"q":105}]',$now)[0]['kind']==='one_sided',
    'One-sided market must never be promoted to trusted BBO.');
$assert($reject(fn()=>$proto->decodeBatch(str_replace('AAPL','TSLA',$frame(120,$ms)),$now)),
    'Unexpected underlying ticker accepted.');
$assert($reject(fn()=>$proto->decodeBatch('[{"ev":"status","status":"error","message":"not authorized"}]',$now)),
    'Unlicensed feed was allowed to continue.');
$assert($reject(fn()=>$proto->decodeBatch($frame(121,'1'),$now)),
    'Stale provider event timestamp accepted in LIVE mode.');
$delayed=new MassiveStocksQuoteProtocol(
    MarketSourceId::fromString('massive.delayed'),'ref|AAPL|BBO','AAPL',
    MarketDataMode::Delayed,'test-vault-key',
);
$delayed->subscribeFrames(['ref|AAPL|BBO'],1);
$delayed->decodeBatch('[{"ev":"status","status":"auth_success"}]',$now);
$delayed->subscriptionFrame();
$delayed->decodeBatch('[{"ev":"status","status":"success","message":"subscribed to: Q.AAPL"}]',$now);
$delayedAt=(string)(($now->getTimestamp()-900)*1000);
$delayedQuote=$delayed->decodeBatch($frame(1,$delayedAt),$now);
$assert($delayedQuote[0]['raw']->mode===MarketDataMode::Delayed,'Delayed quote mislabeled LIVE.');
$assert($reject(fn()=>$delayed->decodeBatch($frame(2,$ms),$now)),
    'LIVE quote accepted on DELAYED entitlement.');
echo "Massive stock NBBO websocket protocol passed.\n";
