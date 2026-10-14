<?php
declare(strict_types=1);
$base = dirname(__DIR__,2).'/app/';
require_once $base.'Kernel/Shared/Domain/ValueObject.php';
foreach (['Decimal','AssetCode','Price','Quantity'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/Value/'.$name.'.php';
foreach (['MarketEventType','MarketObservation','OrderBookLevel','MarketOrderBook'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/MarketData/'.$name.'.php';
foreach (['OrderBookRebuilder','L2RecoverySession','BybitL2RecoveryCoordinator'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/Service/'.$name.'.php';
foreach (['L2SubscriptionConfig','L2SubscriptionEncoder','L2SubscriptionAcknowledgement','L2WebSocketTransport','L2FrameSizeGuard','L2ControlFrameFilter','L2WebSocketFrameRouter','L2FrameDispatch','L2DomainBookFactory','BybitL2StreamProcessor','BinanceL2SnapshotDecoder','BinanceL2SnapshotClient','BinanceL2StreamProcessor','KrakenL2StreamProcessor','L2StreamWorker'] as $name) require_once $base.'Domains/CapitalMarkets/Infrastructure/MarketData/L2/'.$name.'.php';
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Service\{L2RecoverySession,BybitL2RecoveryCoordinator};
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{L2SubscriptionConfig,L2SubscriptionEncoder,L2WebSocketTransport,L2FrameSizeGuard,L2ControlFrameFilter,L2WebSocketFrameRouter,L2FrameDispatch,L2DomainBookFactory,BybitL2StreamProcessor,L2StreamWorker};
class FixtureL2Transport extends L2WebSocketTransport {
    public array $messages = [
        '{"op":"subscribe","success":true}',
        '{"topic":"orderbook.50.BTCUSDT","type":"snapshot","data":{"s":"BTCUSDT","u":10,"seq":100,"b":[["100","1"]],"a":[["110","1"]]}}',
        '{"topic":"orderbook.50.BTCUSDT","type":"delta","data":{"s":"BTCUSDT","u":11,"seq":101,"b":[["100","2"]],"a":[]}}'
    ];
    public array $sent = [];
    public function connect(string $url): void { if (!str_starts_with($url,'wss://')) throw new RuntimeException('TLS missing.'); }
    public function sendText(string $payload): void { $this->sent[] = $payload; }
    public function readText(): string { return array_shift($this->messages) ?? throw new RuntimeException('Fixture exhausted.'); }
    public function close(): void {}
}
$session = new L2RecoverySession();
$transport = new FixtureL2Transport();
$worker = new L2StreamWorker(
    new L2SubscriptionConfig('BYBIT_LINEAR','BTCUSDT',50),
    new L2SubscriptionEncoder(), $transport,
    new BybitL2StreamProcessor(
        new L2FrameDispatch(new L2FrameSizeGuard(),new L2ControlFrameFilter(),new L2WebSocketFrameRouter('BYBIT_LINEAR','BTCUSDT'),'BYBIT_LINEAR'),
        new L2DomainBookFactory(new AssetCode('BTC'),new AssetCode('USDT')),
        new BybitL2RecoveryCoordinator($session),$session
    )
);
$published = [];
$count = $worker->run(static function($book) use (&$published):void {$published[]=$book->bids[0]->quantity->value->value();},3);
if ($count !== 2 || $published !== ['1','2'] || count($transport->sent) !== 1) throw new RuntimeException('L2 worker publication regression.');
$badTransport = new FixtureL2Transport();
$badTransport->messages = ['{"op":"subscribe","success":false}'];
$badSession = new L2RecoverySession();
$badWorker = new L2StreamWorker(
    new L2SubscriptionConfig('BYBIT_LINEAR','BTCUSDT',50),
    new L2SubscriptionEncoder(), $badTransport,
    new BybitL2StreamProcessor(
        new L2FrameDispatch(new L2FrameSizeGuard(),new L2ControlFrameFilter(),new L2WebSocketFrameRouter('BYBIT_LINEAR','BTCUSDT',50),'BYBIT_LINEAR'),
        new L2DomainBookFactory(new AssetCode('BTC'),new AssetCode('USDT')),
        new BybitL2RecoveryCoordinator($badSession),$badSession
    )
);
$rejected = false;
try { $badWorker->run(static function():void { throw new RuntimeException('Rejected stream published.'); },1); }
catch (DomainException $e) { $rejected = true; }
if (!$rejected) throw new RuntimeException('Rejected subscription not blocked.');
$missingTransport = new FixtureL2Transport();
$missingTransport->messages = ['{"op":"pong"}'];
$missingSession = new L2RecoverySession();
$missingWorker = new L2StreamWorker(
    new L2SubscriptionConfig('BYBIT_LINEAR','BTCUSDT',50),
    new L2SubscriptionEncoder(), $missingTransport,
    new BybitL2StreamProcessor(
        new L2FrameDispatch(new L2FrameSizeGuard(),new L2ControlFrameFilter(),new L2WebSocketFrameRouter('BYBIT_LINEAR','BTCUSDT',50),'BYBIT_LINEAR'),
        new L2DomainBookFactory(new AssetCode('BTC'),new AssetCode('USDT')),
        new BybitL2RecoveryCoordinator($missingSession),$missingSession
    )
);
$missingRejected = false;
try { $missingWorker->run(static function():void {},1); }
catch (DomainException $e) { $missingRejected = true; }
if (!$missingRejected) throw new RuntimeException('Missing subscription ack accepted.');
echo "L2 stream worker smoke passed\n";
