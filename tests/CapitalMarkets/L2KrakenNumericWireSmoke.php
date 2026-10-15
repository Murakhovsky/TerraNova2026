<?php
declare(strict_types=1);

$root = dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/';
require_once $root.'L2WebSocketFrameRouter.php';

use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2WebSocketFrameRouter;
$router = new L2WebSocketFrameRouter('KRAKEN_SPOT', 'BTC/USD', 10);
$raw = '{"channel":"book","type":"snapshot","data":[{"symbol":"BTC/USD","checksum":12345,'
    .'"label":"escaped \\"price\\": 9.99",'
    .'"bids":[{"price":100.000000000000000001,"qty":1.000000000000000009}],'
    .'"asks":[{"price":101.01,"qty":2}]}]}';
$decoded = $router->decode($raw);
if (($decoded['type'] ?? null) !== 'snapshot'
    || $decoded['checksum'] !== '12345'
    || $decoded['bids'][0] !== ['price'=>'100.000000000000000001','qty'=>'1.000000000000000009']
    || $decoded['asks'][0] !== ['price'=>'101.01','qty'=>'2']) {
    throw new RuntimeException('Kraken numeric-wire decoding rounded market decimals.');
}
$bad = str_replace('"price":100.000000000000000001','"price":1e-8',$raw);
$rejected = false;
try { $router->decode($bad); } catch (DomainException) { $rejected = true; }
if (!$rejected) throw new RuntimeException('Kraken exponent decimal was rounded or silently accepted.');
echo "Kraken exact JSON number preservation smoke passed\n";
