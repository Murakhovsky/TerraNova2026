<?php
declare(strict_types=1);
$base = dirname(__DIR__,2).'/app/';
require_once $base.'Kernel/Shared/Domain/ValueObject.php';
foreach (['Decimal','AssetCode','Price','Quantity'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/Value/'.$name.'.php';
foreach (['MarketEventType','MarketObservation','OrderBookLevel','MarketOrderBook'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/MarketData/'.$name.'.php';
foreach (['OrderBookRebuilder','L2RecoverySession'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/Service/'.$name.'.php';
foreach (['BinanceL2SequenceValidator','BinanceL2RecoveryCoordinator','BybitL2RecoveryCoordinator'] as $name) require_once $base.'Domains/CapitalMarkets/Infrastructure/MarketData/L2/'.$name.'.php';
foreach (['L2FrameSizeGuard','L2ControlFrameFilter','L2WebSocketFrameRouter','L2FrameDispatch','L2DomainBookFactory','BinanceL2StreamProcessor','BybitL2StreamProcessor'] as $name) require_once $base.'Domains/CapitalMarkets/Infrastructure/MarketData/L2/'.$name.'.php';

use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Service\L2RecoverySession;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\BinanceL2RecoveryCoordinator;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{L2FrameSizeGuard,L2ControlFrameFilter,L2WebSocketFrameRouter,L2FrameDispatch,L2DomainBookFactory,BinanceL2StreamProcessor};
$session = new L2RecoverySession();
$processor = new BinanceL2StreamProcessor(
 new L2FrameDispatch(new L2FrameSizeGuard(),new L2ControlFrameFilter(),new L2WebSocketFrameRouter('BINANCE_SPOT','BTCUSDT'),'BINANCE_SPOT'),
 new L2DomainBookFactory(new AssetCode('BTC'),new AssetCode('USDT')),
 new BinanceL2RecoveryCoordinator($session,false),$session
);
$processor->begin();
$processor->receive('{"e":"depthUpdate","s":"BTCUSDT","U":101,"u":102,"b":[["100","2"]],"a":[]}');
if ($processor->trustedBook() !== null) throw new RuntimeException('Premature Binance publication.');
$processor->reconcile(['sequence'=>'100','bids'=>[['price'=>'99','qty'=>'1']],'asks'=>[['price'=>'110','qty'=>'1']]]);
$book = $processor->trustedBook();
if ($book === null || $book->bids[0]->price->value->value() !== '100') throw new RuntimeException('Binance snapshot bridge failed.');
$processor->receive('{"e":"depthUpdate","s":"BTCUSDT","U":103,"u":103,"b":[["100","3"]],"a":[]}');
if ($processor->trustedBook()->bids[0]->quantity->value->value() !== '3') throw new RuntimeException('Binance live delta failed.');
echo "Binance L2 stream processor smoke passed\n";
