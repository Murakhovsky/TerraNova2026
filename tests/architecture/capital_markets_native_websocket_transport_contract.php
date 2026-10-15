<?php
declare(strict_types=1);
$src=file_get_contents(dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/NativeWebSocketTransport.php');
if(!is_string($src))throw new RuntimeException('WebSocket transport source missing.');
foreach(['StreamingWorkerTransportInterface','verify_peer','verify_peer_name','Sec-WebSocket-Accept','stream_select','random_bytes(4)','WebSocket buffer limit exceeded','Unsupported WebSocket opcode'] as $token){
    if(!str_contains($src,$token))throw new RuntimeException('WebSocket safety contract missing: '.$token);
}
echo "Native WebSocket transport static contract passed.\n";
