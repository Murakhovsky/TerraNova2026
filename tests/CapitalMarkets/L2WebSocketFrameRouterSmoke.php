<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2WebSocketFrameRouter.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2WebSocketFrameRouter;
function checkFrame(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$binance = new L2WebSocketFrameRouter('BINANCE_SPOT', 'BTCUSDT');
$b = $binance->decode('{"e":"depthUpdate","s":"BTCUSDT","U":101,"u":102,"b":[["100","2"]],"a":[]}');
checkFrame($b['U'] === '101' && $b['bids'][0]['qty'] === '2', 'Binance delta');
$bybit = new L2WebSocketFrameRouter('BYBIT_LINEAR', 'BTCUSDT');
$v = $bybit->decode('{"topic":"orderbook.50.BTCUSDT","type":"snapshot","data":{"s":"BTCUSDT","u":1,"seq":3,"b":[],"a":[]}}');
checkFrame($v['type'] === 'snapshot' && $v['seq'] === '3', 'Bybit snapshot');
$kraken = new L2WebSocketFrameRouter('KRAKEN_SPOT', 'BTC/USD');
$k = $kraken->decode('{"channel":"book","type":"update","data":[{"symbol":"BTC/USD","checksum":123,"bids":[{"price":"100.00","qty":"1.00"}],"asks":[]}]}');
checkFrame($k['checksum'] === '123' && $k['bids'][0]['price'] === '100.00', 'Kraken delta');
try {
    $binance->decode('{"e":"depthUpdate","s":"ETHUSDT","U":1,"u":2,"b":[],"a":[]}');
    throw new RuntimeException('Wrong symbol accepted');
} catch (DomainException) {}
foreach (['orderbook.50.ETHUSDT', 'orderbook.50.BTCUSDT.extra'] as $topic) {
    $rejected = false;
    try {
        $bybit->decode(json_encode(['topic' => $topic, 'type' => 'delta', 'data' => ['s' => 'BTCUSDT', 'u' => 2, 'seq' => 4, 'b' => [], 'a' => []]], JSON_THROW_ON_ERROR));
    } catch (DomainException $e) {
        $rejected = true;
    }
    checkFrame($rejected, 'Invalid Bybit topic was accepted: '.$topic);
}
echo "L2 frame router smoke passed\n";
