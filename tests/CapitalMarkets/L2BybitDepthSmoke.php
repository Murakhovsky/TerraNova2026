<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2WebSocketFrameRouter.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2WebSocketFrameRouter;
$router = new L2WebSocketFrameRouter('BYBIT_LINEAR','BTCUSDT',50);
$good = '{"topic":"orderbook.50.BTCUSDT","type":"snapshot","data":{"s":"BTCUSDT","u":1,"seq":1,"b":[],"a":[]}}';
$bad = '{"topic":"orderbook.200.BTCUSDT","type":"snapshot","data":{"s":"BTCUSDT","u":1,"seq":1,"b":[],"a":[]}}';
if ($router->decode($good)['type'] !== 'snapshot') throw new RuntimeException('Correct Bybit depth rejected.');
$rejected = false;
try { $router->decode($bad); } catch (DomainException $e) { $rejected = true; }
if (!$rejected) throw new RuntimeException('Mismatched Bybit depth accepted.');
echo "Bybit depth validation smoke passed\n";
