<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\MarketData\StreamingSubscriptionReconciler;
use Domains\CapitalMarkets\Domain\MarketData\StreamingHeartbeatWatchdog;
use Domains\CapitalMarkets\Domain\MarketData\StreamingRecoveryGate;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException($message);
};
$rejects=static function(callable $fn):bool {
    try{$fn();return false;}catch(DomainException){return true;}
};

$registry=new StreamingSubscriptionReconciler();
$registry->setDesired(['binance:BTCUSDT:book','binance:ETHUSDT:book','binance:BTCUSDT:book']);
$registry->resetForGeneration(1);
$assert(count($registry->toSubscribe())===2,'Desired subscriptions should deduplicate.');
$registry->acknowledge(1,'binance:BTCUSDT:book');
$assert(count($registry->toSubscribe())===1,'ACK should update outstanding subscriptions.');
$assert($rejects(fn()=>$registry->acknowledge(0,'binance:ETHUSDT:book')),'Old epoch ACK must be fenced.');
$registry->setDesired(['binance:ETHUSDT:book']);
$assert($registry->toUnsubscribe()===['binance:BTCUSDT:book'],'Removed desired subscription must be unsubscribed.');
$registry->resetForGeneration(2);
$assert($registry->toSubscribe()===['binance:ETHUSDT:book'],'Reconnect must resend desired subscriptions.');
$assert($rejects(fn()=>$registry->acknowledge(1,'binance:ETHUSDT:book')),'Pre-reconnect ACK must fail.');
$registry->acknowledge(2,'binance:ETHUSDT:book');
$assert($registry->allAcknowledged(),'All active desired subscriptions should be acknowledged.');

$watchdog=new StreamingHeartbeatWatchdog(1000,300);
$assert($watchdog->failureReason(100,false)==='NOT_STARTED','Watchdog must fail closed before initialization.');
$watchdog->begin(100);
$watchdog->pong(500);
$assert($watchdog->failureReason(600,false)===null,'Idle session remains healthy with valid heartbeat.');
$assert($watchdog->failureReason(600,true)==='STREAM_STALE','Active stream must expire without data.');
$watchdog->message(600);
$assert($watchdog->failureReason(900,true)===null,'Fresh observation should clear staleness.');
$assert($watchdog->failureReason(1501,false)==='HEARTBEAT_TIMEOUT','Missed heartbeat must be detected even in closed markets.');
$assert($rejects(fn()=>$watchdog->pong(499)),'Nonmonotonic heartbeat must be rejected.');

$gate=new StreamingRecoveryGate();
$epoch=$gate->start();
$gate->connected($epoch);
$gate->acknowledged($epoch);
$gate->snapshot($epoch,'10',true);
$gate->recovered($epoch,true,true);
if($watchdog->failureReason(1501,false)!==null)$gate->disconnect($epoch,'HEARTBEAT_TIMEOUT');
$assert(!$gate->trusted(),'Watchdog failure must trigger revocation in supervisor.');
echo "Capital Markets streaming supervision passed.\n";
