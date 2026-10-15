<?php
declare(strict_types=1);

require_once __DIR__.'/L2StreamWorkerSmoke.php';
$root = dirname(__DIR__, 2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/';
foreach ([
    'BinanceL2SequenceValidator','BinanceL2RecoveryCoordinator',
    'BybitL2RecoveryCoordinator','KrakenL2Checksum','KrakenL2BookRecovery',
    'L2LiveWorkerFactory',
] as $name) require_once $root.$name.'.php';

use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{L2LiveWorkerFactory,L2SubscriptionConfig,L2StreamWorker};

$factory = new L2LiveWorkerFactory();
foreach ([
    ['BINANCE_SPOT','BTCUSDT',50],
    ['BINANCE_FUTURES','BTCUSDT',50],
    ['BYBIT_SPOT','BTCUSDT',50],
    ['BYBIT_LINEAR','BTCUSDT',50],
    ['KRAKEN_SPOT','BTC/USD',10],
] as [$venue,$symbol,$depth]) {
    $config = new L2SubscriptionConfig($venue,$symbol,$depth);
    $first = $factory->create($config,new AssetCode('BTC'),new AssetCode('USDT'),30);
    $second = $factory->create($config,new AssetCode('BTC'),new AssetCode('USDT'),30);
    if (!$first instanceof L2StreamWorker || $first === $second) {
        throw new RuntimeException('L2 worker factory failed: '.$venue);
    }
}
echo "L2 live worker factory smoke passed\n";
