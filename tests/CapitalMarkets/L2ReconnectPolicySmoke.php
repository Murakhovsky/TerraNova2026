<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2ReconnectPolicy.php';

use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2ReconnectPolicy;

$policy = new L2ReconnectPolicy(100, 500, 5);
foreach ([1 => 100, 2 => 200, 3 => 400, 4 => 500, 5 => 500] as $attempt => $expected) {
    if ($policy->delayMs($attempt) !== $expected) throw new RuntimeException('Reconnect delay mismatch.');
}
foreach ([0, 6] as $attempt) {
    $rejected = false;
    try { $policy->delayMs($attempt); } catch (DomainException $e) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Reconnect attempt outside budget accepted.');
}
echo "L2 reconnect policy smoke passed\n";
