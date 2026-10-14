<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2SubscriptionAcknowledgement.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2SubscriptionAcknowledgement;
$a = new L2SubscriptionAcknowledgement();
if (!$a->accepted('BYBIT_SPOT','{"op":"subscribe","success":true}')) throw new RuntimeException('Bybit ack missing.');
if (!$a->accepted('KRAKEN_SPOT','{"method":"subscribe","success":true}')) throw new RuntimeException('Kraken ack missing.');
if ($a->accepted('BYBIT_LINEAR','{"op":"pong"}')) throw new RuntimeException('Pong accepted as subscription.');
foreach ([['BYBIT_LINEAR','{"op":"subscribe","success":false}'],['KRAKEN_SPOT','{"method":"subscribe","success":false}']] as [$venue,$json]) {
    $rejected = false;
    try { $a->accepted($venue,$json); } catch (DomainException $e) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Failed subscription accepted.');
}
echo "L2 subscription acknowledgement smoke passed\n";
