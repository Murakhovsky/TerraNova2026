<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/BinanceL2SnapshotDecoder.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\BinanceL2SnapshotDecoder;
$decoder = new BinanceL2SnapshotDecoder();
$book = $decoder->decode('{"lastUpdateId":9007199254740993,"bids":[["100.00","2.00"]],"asks":[["101.00","1.00"]]}');
if ($book['sequence'] !== '9007199254740993' || $book['bids'][0]['price'] !== '100.00') throw new RuntimeException('Snapshot precision regression.');
foreach (['{"lastUpdateId":1,"bids":[["0","1"]],"asks":[]}', '{"lastUpdateId":1,"bids":[["100",1]],"asks":[]}', '{"lastUpdateId":1,"bids":{},"asks":[]}'] as $json) {
    $rejected = false;
    try { $decoder->decode($json); } catch (DomainException $e) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Invalid snapshot accepted.');
}
echo "Binance snapshot decoder smoke passed\n";
