<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/';
foreach (['L2FrameSizeGuard','L2ControlFrameFilter','L2WebSocketFrameRouter','L2FrameDispatch'] as $name) require_once $root.$name.'.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2FrameSizeGuard;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2ControlFrameFilter;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2WebSocketFrameRouter;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2FrameDispatch;
$dispatch = new L2FrameDispatch(new L2FrameSizeGuard(1024), new L2ControlFrameFilter(), new L2WebSocketFrameRouter('BYBIT_LINEAR', 'BTCUSDT'), 'BYBIT_LINEAR');
if ($dispatch->decode('{"op":"pong"}') !== null) throw new RuntimeException('Heartbeat leaked into order book.');
$frame = $dispatch->decode('{"topic":"orderbook.50.BTCUSDT","type":"delta","data":{"s":"BTCUSDT","u":2,"seq":3,"b":[],"a":[]}}');
if ($frame['u'] !== '2') throw new RuntimeException('L2 frame dispatch failed.');
$rejected = false;
try { $dispatch->decode('{"op":"subscribe","success":false}'); } catch (DomainException $e) { $rejected = true; }
if (!$rejected) throw new RuntimeException('Rejected subscription ignored.');
echo "L2 frame dispatch smoke passed\n";
