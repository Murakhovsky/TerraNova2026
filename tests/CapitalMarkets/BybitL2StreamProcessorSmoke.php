<?php
declare(strict_types=1);
$base = dirname(__DIR__,2).'/app/';
require_once $base.'Kernel/Shared/Domain/ValueObject.php';
foreach (['Decimal','AssetCode','Price','Quantity'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/Value/'.$name.'.php';
foreach (['MarketEventType','OrderBookLevel','MarketOrderBook'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/MarketData/'.$name.'.php';
foreach (['OrderBookRebuilder','L2RecoverySession','BinanceL2SequenceValidator','BinanceL2RecoveryCoordinator','BybitL2RecoveryCoordinator'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/Service/'.$name.'.php';
foreach (['L2FrameSizeGuard','L2ControlFrameFilter','L2WebSocketFrameRouter','L2FrameDispatch','L2DomainBookFactory','BinanceL2StreamProcessor','BybitL2StreamProcessor'] as $name) require_once $base.'Domains/CapitalMarkets/Infrastructure/MarketData/L2/'.$name.'.php';

use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Service\L2RecoverySession;
use Domains\CapitalMarkets\Domain\Service\BybitL2RecoveryCoordinator;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{L2FrameSizeGuard,L2ControlFrameFilter,L2WebSocketFrameRouter,L2FrameDispatch,L2DomainBookFactory,BybitL2StreamProcessor};
$session = new L2RecoverySession();
$processor = new BybitL2StreamProcessor(
 new L2FrameDispatch(new L2FrameSizeGuard(),new L2ControlFrameFilter(),new L2WebSocketFrameRouter('BYBIT_LINEAR','BTCUSDT'),'BYBIT_LINEAR'),
 new L2DomainBookFactory(new AssetCode('BTC'),new AssetCode('USDT')),
 new BybitL2RecoveryCoordinator($session),$session
);
$processor->begin();
$processor->receive('{"topic":"orderbook.50.BTCUSDT","type":"snapshot","data":{"s":"BTCUSDT","u":10,"seq":100,"b":[["100","1"]],"a":[["110","1"]]}}');
if ($processor->trustedBook() === null) throw new RuntimeException('Bybit snapshot not trusted.');
$processor->receive('{"topic":"orderbook.50.BTCUSDT","type":"delta","data":{"s":"BTCUSDT","u":11,"seq":101,"b":[["100","2"]],"a":[]}}');
if ($processor->trustedBook()->bids[0]->quantity->value->value() !== '2') throw new RuntimeException('Bybit delta not applied.');
$processor->receive('{"topic":"orderbook.50.BTCUSDT","type":"snapshot","data":{"s":"BTCUSDT","u":1,"seq":200,"b":[["99","1"]],"a":[["110","1"]]}}');
if ($processor->trustedBook()->bids[0]->price->value->value() !== '99') throw new RuntimeException('Bybit restart snapshot not applied.');
echo "Bybit L2 stream processor smoke passed\n";
