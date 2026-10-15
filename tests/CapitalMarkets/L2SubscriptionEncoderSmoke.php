<?php
declare(strict_types=1);
$root = dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/';
require_once $root.'L2SubscriptionConfig.php';
require_once $root.'L2SubscriptionEncoder.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2SubscriptionConfig;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2SubscriptionEncoder;
$encoder = new L2SubscriptionEncoder();
if ($encoder->encode(new L2SubscriptionConfig('BINANCE_SPOT','BTCUSDT')) !== null) throw new RuntimeException('Binance should use URL stream.');
$bybit = json_decode($encoder->encode(new L2SubscriptionConfig('BYBIT_LINEAR','BTCUSDT',50)),true,512,JSON_THROW_ON_ERROR);
if ($bybit['args'][0] !== 'orderbook.50.BTCUSDT') throw new RuntimeException('Bybit topic mismatch.');
$kraken = json_decode($encoder->encode(new L2SubscriptionConfig('KRAKEN_SPOT','BTC/USD',10)),true,512,JSON_THROW_ON_ERROR);
if ($kraken['params']['symbol'][0] !== 'BTC/USD') throw new RuntimeException('Kraken symbol mismatch.');
echo "L2 subscription encoder smoke passed\n";
