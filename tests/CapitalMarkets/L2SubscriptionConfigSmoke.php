<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2SubscriptionConfig.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2SubscriptionConfig;
foreach ([['BINANCE_SPOT','BTCUSDT',50],['BINANCE_FUTURES','BTCUSDT',50],['BYBIT_SPOT','BTCUSDT',50],['BYBIT_LINEAR','BTCUSDT',200],['KRAKEN_SPOT','BTC/USD',10]] as $args) {
    $config = new L2SubscriptionConfig(...$args);
    if (!str_starts_with($config->websocketUrl(), 'wss://')) throw new RuntimeException('Non-TLS endpoint');
    if (str_starts_with($config->venue, 'BINANCE_') && $config->snapshotUrl() === null) throw new RuntimeException('Missing REST snapshot');
    if (!str_starts_with($config->venue, 'BINANCE_') && $config->subscribeFrame() === null) throw new RuntimeException('Missing subscribe frame');
}
foreach ([['BYBIT_SPOT','BTCUSDT',10],['KRAKEN_SPOT','BTC/USD',50],['BINANCE_SPOT','BTCUSDT&x=1',50]] as $args) {
    $rejected = false;
    try { new L2SubscriptionConfig(...$args); } catch (DomainException $e) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Invalid subscription accepted');
}

$binanceSpot = new L2SubscriptionConfig('BINANCE_SPOT','BTCUSDT',50);
$binanceFutures = new L2SubscriptionConfig('BINANCE_FUTURES','BTCUSDT',50);
if (!str_starts_with($binanceSpot->websocketUrl(), 'wss://data-stream.binance.vision:443/ws/')
    || !str_starts_with((string)$binanceSpot->snapshotUrl(), 'https://data-api.binance.vision/api/v3/depth')
    || !str_starts_with($binanceFutures->websocketUrl(), 'wss://fstream.binance.com/public/ws/')) {
    throw new RuntimeException('Binance market-data endpoints are not current.');
}
echo "L2 subscription config smoke passed\n";
