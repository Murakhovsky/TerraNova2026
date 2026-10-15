<?php
declare(strict_types=1);

require_once __DIR__.'/L2LiveWorkerFactorySmoke.php';
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{L2LiveWorkerFactory,L2SubscriptionConfig};

$factory = new L2LiveWorkerFactory();
$config = new L2SubscriptionConfig('BYBIT_LINEAR','BTCUSDT',50);
$snapshot = '{"topic":"orderbook.50.BTCUSDT","type":"snapshot","data":{"s":"BTCUSDT","u":10,"seq":100,"b":[["100","1"]],"a":[["110","1"]]}}';
$ack = '{"op":"subscribe","success":true}';
$delta = '{"topic":"orderbook.50.BTCUSDT","type":"delta","data":{"s":"BTCUSDT","u":11,"seq":101,"b":[["100","2"]],"a":[]}}';

// Real Bybit Linear may deliver the order book before the control ACK.
// The book must be verified but never published before ACK.
$earlyTransport = new FixtureL2Transport();
$earlyTransport->messages = [$snapshot, $ack, $delta];
$early = $factory->create($config,new AssetCode('BTC'),new AssetCode('USDT'),30,$earlyTransport);
$published = [];
$count = $early->run(static function ($book) use (&$published): void {
    $published[] = $book->bids[0]->quantity->value->value();
}, 3);
if ($count !== 2 || $published !== ['1','2']) {
    throw new RuntimeException('Bybit before-ACK data was lost or duplicated.');
}

$missing = new FixtureL2Transport();
$missing->messages = [$snapshot,'{"op":"pong"}'];
$untrusted = $factory->create($config,new AssetCode('BTC'),new AssetCode('USDT'),30,$missing);
$leaked = false;
$blocked = false;
try {
    $untrusted->run(static function($book) use (&$leaked):void { $leaked = true; },2);
} catch (DomainException) { $blocked = true; }
if (!$blocked || $leaked) {
    throw new RuntimeException('Pre-ACK Bybit market data was published without confirmed subscription.');
}
echo "Bybit data-before-ack fail-closed smoke passed\n";
