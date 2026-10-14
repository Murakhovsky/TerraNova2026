<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2WebSocketTransport.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2WebSocketTransport;
$t = new L2WebSocketTransport();
foreach (['ws://localhost/','https://example.com/','wss://user:secret@example.com/','wss://example.com/path#fragment'] as $url) {
    $rejected = false;
    try { $t->connect($url); } catch (DomainException $e) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Unsafe websocket URL accepted.');
}
$rejected = false;
try { $t->sendText('data'); } catch (DomainException $e) { $rejected = true; }
if (!$rejected) throw new RuntimeException('Disconnected websocket accepted outbound data.');
echo "L2 websocket transport smoke passed\n";
