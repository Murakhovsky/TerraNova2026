<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\MarketData\StreamingRecoveryGate;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
};
$rejects=static function(callable $action):bool {
    try{$action();return false;}catch(DomainException){return true;}
};

$gate=new StreamingRecoveryGate();
$assert(!$gate->trusted(),'Disabled must never be trusted.');
$epoch=$gate->start();
$assert($rejects(fn()=>$gate->acknowledged($epoch)),'Cannot acknowledge before connecting.');
$gate->connected($epoch);
$gate->acknowledged($epoch);
$assert(!$gate->trusted(),'Subscription acknowledgement cannot confer trust.');
$gate->snapshot($epoch,'100',true);
$assert(!$gate->trusted(),'Snapshot alone cannot confer trust.');
$gate->recovered($epoch,true,true);
$assert($gate->trusted(),'Verified snapshot plus continuity and freshness permits trust.');
$gate->delta($epoch,'101',true,true);
$assert($gate->lastSequence()==='101','Valid delta should update checkpoint.');
$gate->delta($epoch,'103',false,true);
$assert(!$gate->trusted(),'Broken continuity must revoke trust.');
$assert($gate->reason()==='DELTA_INTEGRITY_FAILED','Integrity incident reason lost.');

$newEpoch=$gate->start();
$assert($newEpoch>$epoch,'Reconnect must advance generation.');
$assert(!$gate->trusted(),'Reconnect must start untrusted.');
$assert($rejects(fn()=>$gate->delta($epoch,'104',true,true)),'Old-epoch event must be fenced.');
$gate->connected($newEpoch);
$gate->acknowledged($newEpoch);
$gate->snapshot($newEpoch,'200',false);
$assert(!$gate->trusted(),'Rejected snapshot must never confer trust.');
$assert($rejects(fn()=>$gate->recovered($newEpoch,true,true)),'Invalid snapshot requires a fresh recovery cycle.');

$last=$gate->start();
$gate->connected($last);
$gate->acknowledged($last);
$gate->snapshot($last,'300',true);
$gate->recovered($last,false,true);
$assert(!$gate->trusted(),'Failed recovery validation must revoke trust.');
$gate->disable();
$assert($rejects(fn()=>$gate->connected($last)),'Disabled worker must reject old epoch.');
$assert(!$gate->trusted(),'Disabled stream must be untrusted.');

echo "Capital Markets streaming recovery gate passed.\n";
