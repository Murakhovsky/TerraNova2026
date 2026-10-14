<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/BinanceL2SnapshotDecoder.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\BinanceL2SnapshotDecoder;

$decoder = new BinanceL2SnapshotDecoder();
$wire = '{"id":"l2-test-1","status":200,"result":{"lastUpdateId":9007199254740993,'
    .'"bids":[["100.00000001","2.00"]],"asks":[["100.00000002","1.00"]]}}';
$book = $decoder->decodeFuturesWebSocketDepth($wire,'l2-test-1');
if ($book['sequence'] !== '9007199254740993'
    || $book['bids'][0]['price'] !== '100.00000001'
    || $book['asks'][0]['price'] !== '100.00000002') {
    throw new RuntimeException('Binance Futures public WS snapshot lost decimal/sequence precision.');
}
foreach ([
    [$wire,'l2-other'],
    [str_replace('"status":200','"status":429',$wire),'l2-test-1'],
    [str_replace('"result":{"lastUpdateId"','"result":{"invalid":5,"lastUpdateId"',$wire),'l2-other'],
    ['{"id":"l2-test-1","status":200,"result":{"bids":[],"asks":[]}}','l2-test-1'],
] as [$json,$id]) {
    $rejected = false;
    try { $decoder->decodeFuturesWebSocketDepth($json,$id); }
    catch (DomainException) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Untrusted Futures WS depth snapshot accepted.');
}
echo "Binance Futures public websocket snapshot decoder smoke passed\n";
