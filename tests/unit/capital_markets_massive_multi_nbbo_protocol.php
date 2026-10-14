<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MassiveStocksMultiNbboProtocol;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $valid,string $reason):void{
    if(!$valid)throw new RuntimeException($reason);
};
$reject=static function(callable $callback):bool{
    try{$callback();return false;}catch(\DomainException|\InvalidArgumentException){return true;}
};
$keys=['src|AAPL|BBO'=>'AAPL','src|MSFT|BBO'=>'MSFT'];
$protocol=new MassiveStocksMultiNbboProtocol(
    MarketSourceId::fromString('massive.multi'),$keys,
    MarketDataMode::Live,'CI_SECRET_NEVER_PERSIST',
);
$auth=$protocol->subscribeFrames(array_keys($keys),1);
$assert(str_contains($auth[0],'"action":"auth"')
    &&!str_contains($auth[0],'"action":"subscribe"'),
    'Massive multi must authenticate before multi subscribe.');
$assert($reject(fn()=>$protocol->subscriptionFrame()),
    'Batch subscribe before auth succeeded.');
$now=new DateTimeImmutable('2026-10-13T12:00:01+00:00');
$ms=(string)($now->getTimestamp()*1000);
$quote=static function(string $symbol,string $sequence,string $bid,string $timestamp,string $ask='205.15') :string{
    return '{"ev":"Q","sym":"'.$symbol.'","bp":'.$bid.',"bs":100,'.
        '"ap":'.$ask.',"as":200,"t":'.$timestamp.',"q":'.$sequence.'}';
};
$frame=static fn(string ...$items):string=>'['.implode(',',$items).']';
$assert($reject(fn()=>$protocol->decodeBatch(
    $frame($quote('AAPL','5','205.123456789123456789',$ms)),$now)),
    'Pre-ACK Massive multi quote accepted.');
$authResult=$protocol->decodeBatch(
    '[{"ev":"status","status":"connected"},{"ev":"status","status":"auth_success"}]',$now);
$assert(count($authResult)===2&&$authResult[1]['kind']==='auth',
    'Multi auth state machine did not record authentication.');
$sub=json_decode($protocol->subscriptionFrame(),true,32,JSON_THROW_ON_ERROR);
$assert($sub['action']==='subscribe'&&$sub['params']==='Q.AAPL,Q.MSFT',
    'Massive multi subscription must combine exact tickers.');
$assert($reject(fn()=>$protocol->subscriptionFrame()),'Repeated multi SUBSCRIBE accepted.');
$acks=$protocol->decodeBatch(
    '[{"ev":"status","status":"success","message":"subscribed to: Q.AAPL"}]',$now);
$assert(count($acks)===1&&$acks[0]['key']==='src|AAPL|BBO',
    'Missing per-channel AAPL ACK.');
$assert($reject(fn()=>$protocol->decodeBatch(
    $frame($quote('AAPL','5','205.123456789123456789',$ms)),$now)),
    'Data published while another channel unacknowledged.');
$acks=$protocol->decodeBatch(
    '[{"ev":"status","status":"success","message":"subscribed to: Q.MSFT"}]',$now);
$assert($acks[0]['key']==='src|MSFT|BBO',
    'Missing independent MSFT ACK.');
$batch=$protocol->decodeBatch($frame(
    $quote('MSFT','2','205.21',$ms),$quote('AAPL','5','205.123456789123456789',$ms),
),$now);
$assert(count($batch)===2&&$batch[0]['symbol']==='MSFT'
    &&$batch[1]['symbol']==='AAPL',
    'Massive multi batch lost independent ticker identity.');
$assert($batch[0]['raw']->externalInstrument==='MSFT'
    &&$batch[1]['raw']->venueId===null,
    'Massive multi quotes must be equity reference states.');
$normalized=json_decode($batch[1]['raw']->rawPayload['raw_json'],true,32,JSON_THROW_ON_ERROR);
$assert($normalized['bid_price']==='205.123456789123456789',
    'Exact Massive multi decimal corrupted.');
$assert(!str_contains(json_encode($batch[1]['raw']->rawPayload,JSON_THROW_ON_ERROR),
    'CI_SECRET_NEVER_PERSIST'),'Secret leaked to persisted RawMarketEvent.');
$assert($protocol->decodeBatch(
    $frame($quote('AAPL','8','205.12',$ms)),$now)[0]['kind']==='quote',
    'Noncontiguous monotonic per-symbol update rejected.');
$assert($protocol->decodeBatch(
    $frame($quote('MSFT','3','205.24',$ms)),$now)[0]['kind']==='quote',
    'MSFT independent sequence wrongly compared with AAPL.');
$assert($protocol->decodeBatch(
    $frame($quote('MSFT','3','205.24',$ms)),$now)[0]['kind']==='heartbeat',
    'Duplicate MSFT quote was published.');
$assert($reject(fn()=>$protocol->decodeBatch(
    $frame($quote('TSLA','10','205.1',$ms)),$now)),
    'Undeclared underlying accepted.');
$assert($reject(fn()=>$protocol->decodeBatch(
    '[{"ev":"status","status":"error","message":"not authorized"}]',$now)),
    'Provider entitlement rejection ignored.');
$after=$now->modify('+105 seconds');
$afterMs=(string)($after->getTimestamp()*1000);
$assert($protocol->decodeBatch(
    $frame($quote('AAPL','9','205.27',$afterMs)),$after)[0]['kind']==='quote',
    'Valid AAPL quote after MSFT silence was rejected.');
$assert($protocol->staleSymbol($now->modify('+121 seconds'),120)==='MSFT',
    'Fresh AAPL incorrectly kept a stale MSFT subscription trusted.');
$assert($protocol->staleSymbol($now->modify('+120 seconds'),120)===null,
    'Boundary time should not falsely invalidate an NBBO symbol.');
$recoveryAt=$now->modify('+122 seconds');
$recoveryMs=(string)($recoveryAt->getTimestamp()*1000);
$protocol->quarantineSymbol('MSFT');
$softRecovered=$protocol->decodeBatch(
    $frame($quote('MSFT','4','205.31',$recoveryMs)),$recoveryAt,
);
$assert($softRecovered[0]['kind']==='quote'&&$softRecovered[0]['symbol']==='MSFT',
    'A stale ticker should recover on a newer full NBBO without subscription churn.');
$healthyAapl=$protocol->decodeBatch(
    $frame($quote('AAPL','10','205.30',$recoveryMs)),$recoveryAt,
);
$assert($healthyAapl[0]['kind']==='quote'&&$healthyAapl[0]['symbol']==='AAPL',
    'MSFT recovery interfered with the healthy AAPL sequence.');
$rollback=$protocol->decodeBatch(
    $frame($quote('MSFT','4','205.32',$recoveryMs)),$recoveryAt,
);
$assert($rollback[0]['kind']==='reset'&&$rollback[0]['symbol']==='MSFT',
    'MSFT sequence rollback did not quarantine only that ticker.');
$ignoredMsft=$protocol->decodeBatch(
    $frame($quote('MSFT','5','205.33',$recoveryMs)),$recoveryAt,
);
$assert($ignoredMsft[0]['kind']==='quarantined',
    'A ticker with a sequence rollback published before a fresh subscription ACK.');
$unsub=json_decode($protocol->unsubscribeFrame('MSFT'),true,32,JSON_THROW_ON_ERROR);
$assert($unsub['action']==='unsubscribe'&&$unsub['params']==='Q.MSFT',
    'Hard quarantine must unsubscribe only the affected ticker.');
$unsubAck=$protocol->decodeBatch(
    '[{"ev":"status","status":"success","message":"unsubscribed from: Q.MSFT"}]',$recoveryAt,
);
$assert($unsubAck[0]['kind']==='unsubscribed'&&$unsubAck[0]['symbol']==='MSFT',
    'Per-symbol unsubscribe ACK was not processed.');
$resub=json_decode($protocol->resubscribeFrame('MSFT'),true,32,JSON_THROW_ON_ERROR);
$assert($resub['action']==='subscribe'&&$resub['params']==='Q.MSFT',
    'Per-symbol recovery did not resubscribe only the quarantined ticker.');
$hardRecoveryAt=$now->modify('+123 seconds');
$hardRecoveryMs=(string)($hardRecoveryAt->getTimestamp()*1000);
$subAck=$protocol->decodeBatch(
    '[{"ev":"status","status":"success","message":"subscribed to: Q.MSFT"}]',$hardRecoveryAt,
);
$assert($subAck[0]['kind']==='ack'&&$subAck[0]['symbol']==='MSFT',
    'Fresh per-symbol subscribe ACK was not recognized.');
$hardRecovered=$protocol->decodeBatch(
    $frame($quote('MSFT','1','205.31',$hardRecoveryMs)),$hardRecoveryAt,
);
$assert($hardRecovered[0]['kind']==='quote'&&$hardRecovered[0]['symbol']==='MSFT',
    'Hard-quarantined ticker did not recover from a fresh full NBBO.');
$smallPrice=$protocol->decodeBatch(
    $frame($quote('AAPL','11','0.12',$hardRecoveryMs,'0.13')),$hardRecoveryAt,
);
$assert($smallPrice[0]['kind']==='quote',
    'Valid sub-dollar NBBO prices must not be rejected as numeric zero.');
$oneSide='{"ev":"Q","sym":"AAPL","ap":0.14,"as":100,"t":'.$hardRecoveryMs.',"q":12}';
$oneSided=$protocol->decodeBatch($frame($oneSide),$hardRecoveryAt);
$assert($oneSided[0]['kind']==='one_sided'&&$oneSided[0]['symbol']==='AAPL',
    'One-sided NBBO was incorrectly treated as usable.');
$recoveredAapl=$protocol->decodeBatch(
    $frame($quote('AAPL','13','0.15',$hardRecoveryMs,'0.16')),$hardRecoveryAt,
);
$assert($recoveredAapl[0]['kind']==='quote'&&$recoveredAapl[0]['symbol']==='AAPL',
    'A one-sided ticker should recover on the next newer complete NBBO without reconnecting healthy symbols.');
$protocol->subscribeFrames(array_keys($keys),2);
$assert($reject(fn()=>$protocol->decodeBatch(
    '[{"ev":"status","status":"success","message":"subscribed to: Q.AAPL"}]',$now)),
    'Stale ACK accepted after reconnect.');
$assert($reject(fn()=>new MassiveStocksMultiNbboProtocol(
    MarketSourceId::fromString('massive'),['a'=>'AAPL','b'=>'AAPL'],
    MarketDataMode::Live,'secret',
)),'Duplicate ticker mapping accepted.');
echo "Massive Stocks multi-symbol NBBO state machine passed.\n";
