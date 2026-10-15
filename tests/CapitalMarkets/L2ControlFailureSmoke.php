<?php
declare(strict_types=1);
$base = dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/';
require_once $base.'L2ControlFrameFilter.php';
require_once $base.'L2SubscriptionAcknowledgement.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2ControlFrameFilter;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2SubscriptionAcknowledgement;
$filter = new L2ControlFrameFilter();
$acks = new L2SubscriptionAcknowledgement();
$cases = [
    ['BINANCE_SPOT','{"code":-1003,"msg":"rate limited"}'],
    ['BYBIT_LINEAR','{"op":"subscribe","success":false,"ret_msg":"denied"}'],
    ['KRAKEN_SPOT','{"method":"subscribe","success":false,"error":"denied"}'],
];
foreach ($cases as [$venue,$json]) {
    $rejected = false;
    try { $filter->classify($venue,$json); } catch (DomainException $e) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Control error silently ignored: '.$venue);
}
if (!$acks->accepted('BYBIT_LINEAR','{"op":"subscribe","success":true}')) throw new RuntimeException('Bybit ack rejected.');
if (!$acks->accepted('KRAKEN_SPOT','{"method":"subscribe","success":true}')) throw new RuntimeException('Kraken ack rejected.');
echo "L2 control failure smoke passed\n";
