<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$planner=file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/StreamingSubscriptionPlanner.php');
$gate=file_get_contents($root.'/app/Domains/CapitalMarkets/Domain/MarketData/StreamingRecoveryGate.php');
$registry=file_get_contents($root.'/app/Domains/CapitalMarkets/Domain/MarketData/StreamingSubscriptionReconciler.php');
$watchdog=file_get_contents($root.'/app/Domains/CapitalMarkets/Domain/MarketData/StreamingHeartbeatWatchdog.php');
foreach([$planner,$gate,$registry,$watchdog] as $source){
    if(!is_string($source)||$source==='')throw new RuntimeException('Streaming source missing.');
}
foreach(['MarketSourceRepositoryInterface','MarketSubscriptionRepositoryInterface','MarketSubscriptionStatus::Active'] as $required){
    if(!str_contains($planner,$required))throw new RuntimeException('Streaming planner contract missing: '.$required);
}
if(!str_contains($gate,'Stale streaming connection generation.'))throw new RuntimeException('Epoch fencing missing.');
if(!str_contains($registry,'Stale subscription acknowledgement.'))throw new RuntimeException('ACK epoch fencing missing.');
if(!str_contains($watchdog,'HEARTBEAT_TIMEOUT'))throw new RuntimeException('Heartbeat timeout missing.');
echo "Capital Markets streaming core contracts passed.\n";
