<?php
declare(strict_types=1);
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance\BinanceSpotPayloadParser;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\BinanceSpotMultiBboProtocol;
require dirname(__DIR__,2).'/vendor/autoload.php';
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$reject=static function(callable $fn):bool{try{$fn();return false;}catch(DomainException|InvalidArgumentException){return true;}};
$keys=['s|v|BTC|BBO'=>'BTCUSDT','s|v|ETH|BBO'=>'ETHUSDT'];
$p=new BinanceSpotMultiBboProtocol(MarketSourceId::fromString('binance.spot'),VenueId::fromString('venue:binance'),$keys,new BinanceSpotPayloadParser());
$now=new DateTimeImmutable('2026-10-14T12:00:00+00:00');
$btc='{"u":100,"s":"BTCUSDT","b":"62000.12","B":"1.2","a":"62000.15","A":"2.1"}';
$eth='{"u":110,"s":"ETHUSDT","b":"2450.2","B":"1","a":"2450.3","A":"3"}';
$f=$p->subscribeFrames(array_keys($keys),1);
$check(count($f)===1&&str_contains($f[0],'ethusdt@bookTicker'),'One bounded batch subscription expected.');
$check($reject(fn()=>$p->decode($btc,$now)),'Pre-ACK quote passed.');
$ack=$p->decode('{"id":1,"result":null}',$now);
$check($ack['kind']==='ack'&&count($ack['keys'])===2,'Initial ACK must cover exactly declared batch.');
$check($p->decode($btc,$now)['kind']==='quote','BTC initial quote rejected.');
$check($p->decode($eth,$now)['kind']==='quote','ETH initial quote rejected.');
$check($p->decode($btc,$now)['kind']==='heartbeat','Duplicate Binance sequence republished.');
$check($p->decode(str_replace('"u":100','"u":99',$btc),$now)['kind']==='reset','BTC rollback not isolated.');
$check($p->decode(str_replace('"u":100','"u":101',$btc),$now)['kind']==='quarantined','Hard quarantine bypassed.');
$check($p->decode(str_replace('"u":110','"u":111',$eth),$now)['kind']==='quote','Healthy ETH blocked by BTC rollback.');
$un=json_decode($p->unsubscribeFrame('BTCUSDT'),true,32,JSON_THROW_ON_ERROR);
$check($un['method']==='UNSUBSCRIBE'&&count($un['params'])===1,'Unsubscribe touched sibling.');
$check($reject(fn()=>$p->resubscribeFrame('BTCUSDT')),'Resubscribe before unsubscribe ACK accepted.');
$check($p->decode('{"id":2,"result":null}',$now)['kind']==='unsubscribed','Unsubscribe ACK ignored.');
$re=json_decode($p->resubscribeFrame('BTCUSDT'),true,32,JSON_THROW_ON_ERROR);
$check($re['method']==='SUBSCRIBE'&&$re['params']===['btcusdt@bookTicker'],'Resubscription touched healthy ticker.');
$check($p->decode('{"id":3,"result":null}',$now)['kind']==='resubscribed','Recovery ACK ignored.');
$check($p->decode(str_replace('"u":100','"u":1',$btc),$now)['kind']==='quote','Re-ACKed full BBO could not recover.');
$check($p->staleSymbols($now->modify('+121 seconds'))===['BTCUSDT','ETHUSDT'],'Per-symbol stale deadline incorrect.');
$p->quarantineSymbol('BTCUSDT');
$check($p->staleSymbols($now->modify('+121 seconds'))===['ETHUSDT'],'Quarantined ticker should not hide stale sibling.');
$check($p->decode(str_replace('"u":110','"u":112',$eth),$now->modify('+122 seconds'))['kind']==='quote','Healthy ticker recovery blocked.');
$check($p->staleSymbols($now->modify('+123 seconds'))===[],'Recovered ticker marked stale.');
$check($reject(fn()=>$p->decode('{"id":3,"result":null}',$now)),'Stale recovery ACK accepted.');
$check($reject(fn()=>$p->decode(str_replace('"s":"BTCUSDT"','"s":"SOLUSDT"',$btc),$now)),'Undeclared symbol accepted.');
$p->subscribeFrames(array_keys($keys),4);
$check($reject(fn()=>$p->decode($eth,$now)),'Old epoch trust leaked.');
$check($reject(fn()=>new BinanceSpotMultiBboProtocol(
    MarketSourceId::fromString('binance.spot'),VenueId::fromString('venue:binance'),
    ['a'=>'BTCUSDT','b'=>'BTCUSDT'],new BinanceSpotPayloadParser(),
)),'Duplicate external mapping allowed.');
echo "Binance Spot multi-symbol BBO isolation passed.\n";
