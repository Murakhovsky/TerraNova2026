<?php
declare(strict_types=1);
$base = dirname(__DIR__,2).'/app/';
require_once $base.'Kernel/Shared/Domain/ValueObject.php';
foreach (['Decimal','AssetCode','Price','Quantity'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/Value/'.$name.'.php';
foreach (['MarketEventType','MarketObservation','OrderBookLevel','MarketOrderBook'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/MarketData/'.$name.'.php';
foreach (['OrderBookRebuilder','L2RecoverySession','BinanceL2SequenceValidator','BinanceL2RecoveryCoordinator'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/Service/'.$name.'.php';
foreach (['L2FrameSizeGuard','L2ControlFrameFilter','L2WebSocketFrameRouter','L2FrameDispatch','L2DomainBookFactory','BinanceL2StreamProcessor'] as $name) require_once $base.'Domains/CapitalMarkets/Infrastructure/MarketData/L2/'.$name.'.php';
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Service\{L2RecoverySession,BinanceL2RecoveryCoordinator};
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{L2FrameSizeGuard,L2ControlFrameFilter,L2WebSocketFrameRouter,L2FrameDispatch,L2DomainBookFactory,BinanceL2StreamProcessor};
$session = new L2RecoverySession();
$processor = new BinanceL2StreamProcessor(
 new L2FrameDispatch(new L2FrameSizeGuard(),new L2ControlFrameFilter(),new L2WebSocketFrameRouter('BINANCE_SPOT','BTCUSDT'),'BINANCE_SPOT'),
 new L2DomainBookFactory(new AssetCode('BTC'),new AssetCode('USDT')),
 new BinanceL2RecoveryCoordinator($session,false),$session
);
$processor->begin();
$processor->receive('{"e":"depthUpdate","s":"BTCUSDT","U":100,"u":101,"b":[["100","2"]],"a":[]}');
if ($processor->canBridge('105') || $processor->snapshotTooOld('105')) throw new RuntimeException('Future snapshot should await more diffs.');
$processor->receive('{"e":"depthUpdate","s":"BTCUSDT","U":102,"u":106,"b":[["100","3"]],"a":[]}');
if (!$processor->canBridge('105')) throw new RuntimeException('Buffered Binance bridge not detected.');
$processor->reconcile(['sequence'=>'105','bids'=>[['price'=>'99','qty'=>'1']],'asks'=>[['price'=>'110','qty'=>'1']]]);
if ($processor->trustedBook() === null) throw new RuntimeException('Binance bridge not trusted.');
$processor->begin();
$processor->receive('{"e":"depthUpdate","s":"BTCUSDT","U":201,"u":202,"b":[],"a":[]}');
if (!$processor->snapshotTooOld('100')) throw new RuntimeException('Unrecoverable Binance gap not detected.');
echo "Binance buffered bridge smoke passed\n";
