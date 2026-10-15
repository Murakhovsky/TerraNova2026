<?php
declare(strict_types=1);

$root = dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/';
require_once $root.'L2SubscriptionConfig.php';
require_once $root.'L2WorkerRuntimeConfig.php';

use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2WorkerRuntimeConfig;

$base = ['FOS_L2_VENUE'=>'BYBIT_SPOT','FOS_L2_SYMBOL'=>'BTCUSDT','FOS_L2_ENABLED'=>'0'];
$config = L2WorkerRuntimeConfig::fromEnvironment($base);
if ($config->enabled || $config->messageBudget !== 1000 || $config->subscription->depth !== 50) {
    throw new RuntimeException('Safe disabled L2 configuration regression.');
}
$enabled = L2WorkerRuntimeConfig::fromEnvironment($base + ['FOS_L2_MAX_ATTEMPTS'=>'2']);
if ($enabled->maxAttempts !== 2) throw new RuntimeException('Configured retry budget ignored.');
foreach ([
    ['FOS_L2_ENABLED'=>'true'],
    ['FOS_L2_MESSAGE_BUDGET'=>'0'],
    ['FOS_L2_MESSAGE_BUDGET'=>'999999999'],
    ['FOS_L2_STALE_SECONDS'=>'3601'],
    ['FOS_L2_DEPTH'=>'51'],
] as $invalid) {
    $failed = false;
    try { L2WorkerRuntimeConfig::fromEnvironment(array_replace($base,$invalid)); }
    catch (DomainException) { $failed = true; }
    if (!$failed) throw new RuntimeException('Invalid runtime configuration accepted.');
}
echo "L2 runtime configuration smoke passed\n";
