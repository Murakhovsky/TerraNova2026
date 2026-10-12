<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\KrakenSpotTickerProtocol;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\StreamingExactJsonNumbers;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $ok,string $reason):void{
    if(!$ok)throw new RuntimeException($reason);
};
$reject=static function(callable $fn):bool{
    try{$fn();return false;}catch(\DomainException|\InvalidArgumentException){return true;}
};
$now=new DateTimeImmutable('2026-10-12T12:00:00+00:00');
$proto=new KrakenSpotTickerProtocol(
    MarketSourceId::fromString('kraken.spot'),
    VenueId::fromString('venue:kraken'),'org|kraken|AAPLx:USD|BBO','AAPLx/USD',
);
$frames=$proto->subscribeFrames(['org|kraken|AAPLx:USD|BBO'],7);
$request=json_decode($frames[0],true,32,JSON_THROW_ON_ERROR);
$assert(($request['params']['event_trigger']??null)==='bbo','Kraken must use BBO-triggered ticker.');
$assert(($request['params']['symbol']??[])===['AAPLx/USD'],'Kraken pair symbol changed.');

$payload=static function(string $type,string $time,string $bid='205.123456789012345678901234567890'):string{
    return '{"channel":"ticker","type":"'.$type.'","data":[{"symbol":"AAPLx/USD",'.
        '"bid":'.$bid.',"bid_qty":2.5,"ask":205.223456789012345678901234567890,'.
        '"ask_qty":3.5,"timestamp":"'.$time.'"}]}';
};
$t0='2026-10-12T12:00:00.000000Z';
$t1='2026-10-12T12:00:01.000000Z';
$tminus='2026-10-12T11:59:59.000000Z';

$sample=StreamingExactJsonNumbers::object($payload('snapshot',$t0));
$assert($sample['data'][0]['bid']==='205.123456789012345678901234567890',
    'Kraken JSON floating-point conversion silently lost decimal evidence.');
$assert($reject(fn()=>$proto->decode($payload('snapshot',$t0),$now)),
    'Kraken quote arrived before ACK.');
$ack='{"method":"subscribe","success":true,"req_id":7,"result":'.
     '{"channel":"ticker","symbol":"AAPLx/USD","snapshot":true}}';
$assert($proto->decode('{"channel":"status","data":[]}', $now)['kind']==='heartbeat',
    'Kraken status control should not be treated as ticker.');
$assert($proto->decode($ack,$now)['kind']==='ack','Valid Kraken ACK was not accepted.');
$assert($reject(fn()=>$proto->decode($payload('update',$t0),$now)),
    'Ticker update must not replace initial snapshot.');

$quote=$proto->decode($payload('snapshot',$t0),$now,'OPEN');
$assert($quote['kind']==='quote','Initial Kraken snapshot rejected.');
$assert($quote['raw']->sequence===null,'Kraken ticker has no verified L2 sequence.');
$assert($quote['raw']->eventType==='kraken.spot.ws.ticker.bbo','Canonical Kraken decoder route mismatched.');
$assert($quote['raw']->providerTimestamp?->format('Y-m-d\TH:i:s.u\Z')===$t0,
    'Kraken provider timestamp was lost.');
$normalized=json_decode($quote['raw']->rawPayload['raw_json'],true,32,JSON_THROW_ON_ERROR);
$assert($normalized['bid']==='205.123456789012345678901234567890',
    'Normalized Kraken quote has imprecise decimal.');
$assert($proto->decode($payload('snapshot',$t0),$now)['kind']==='heartbeat',
    'Repeated unchanged snapshot must not publish a duplicate.');
$assert($proto->decode($payload('update',$t1,'205.12'),$now)['kind']==='quote',
    'Valid later Kraken BBO update rejected.');
$assert($proto->decode($payload('update',$tminus),$now)['kind']==='reset',
    'Out-of-order timestamp must trigger reset.');
$proto->subscribeFrames(['org|kraken|AAPLx:USD|BBO'],8);
$assert($reject(fn()=>$proto->decode($ack,$now)),'Stale ACK from prior connection accepted.');
$assert($proto->decode(str_replace('"req_id":7','"req_id":8',$ack),$now)['kind']==='ack',
    'New-generation ACK rejected.');
$assert($proto->decode($payload('snapshot',$t0),$now)['kind']==='quote',
    'Fresh generation did not accept a new snapshot.');
$assert($reject(fn()=>$proto->decode(str_replace('AAPLx/USD','TSLAx/USD',$payload('snapshot',$t0)),$now)),
    'Unexpected Kraken xStocks pair accepted.');
echo "Kraken Spot v2 ticker / xStocks protocol passed.\n";
