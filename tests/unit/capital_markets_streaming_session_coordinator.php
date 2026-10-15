<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\StreamingSessionCoordinator;
use Domains\CapitalMarkets\Domain\MarketData\StreamingHeartbeatWatchdog;
use Domains\CapitalMarkets\Domain\MarketData\StreamingRecoveryGate;
use Domains\CapitalMarkets\Domain\MarketData\StreamingSubscriptionReconciler;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $ok,string $reason):void{
    if(!$ok)throw new RuntimeException($reason);
};
$rejects=static function(callable $cb):bool{
    try{$cb();return false;}catch(DomainException){return true;}
};
$session=new StreamingSessionCoordinator(
    new StreamingRecoveryGate(),
    new StreamingSubscriptionReconciler(),
    new StreamingHeartbeatWatchdog(1000,500),
);
$epoch=$session->start(['bybit|BTCUSDT|BBO'],100);
$assert(!$session->trusted(),'Connection start cannot be trusted.');
$session->socketConnected($epoch);
$assert($session->pending()===['bybit|BTCUSDT|BBO'],'Desired subscription missing.');
$session->subscribed($epoch,'bybit|BTCUSDT|BBO');
$assert($session->status()==='SYNCING','All ACKs should permit snapshot synchronization.');
$assert(!$session->trusted(),'ACK must not confer trust.');
$session->verifiedSnapshot($epoch,'100',true);
$session->verifiedRecovery($epoch,true,true);
$assert($session->trusted(),'Only verified recovered state is trusted.');
$session->verifiedDelta($epoch,'101',true,true);
$assert($session->tick($epoch,800,false)===null,'Valid heartbeat must survive an idle market session.');
$assert($session->tick($epoch,800,true)==='STREAM_STALE','Market update silence must expire active feed.');
$assert(!$session->trusted(),'Expired feed must revoke trust.');
$next=$session->start(['bybit|BTCUSDT|BBO'],850);
$assert(!$session->trusted(),'New generation must start untrusted.');
$assert($rejects(fn()=>$session->subscribed($epoch,'bybit|BTCUSDT|BBO')),'Stale ACK must be fenced.');
$session->socketConnected($next);
$session->subscribed($next,'bybit|BTCUSDT|BBO');
$session->verifiedSnapshot($next,'200',true);
$session->verifiedRecovery($next,true,true);
$assert($session->trusted(),'Revalidated new generation must restore trust.');
$session->disconnected($next,'PROVIDER_DISCONNECT');
$assert(!$session->trusted(),'Provider disconnect must fail closed.');
echo "Capital Markets streaming session coordinator passed.\n";
