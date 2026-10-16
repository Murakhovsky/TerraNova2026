<?php
declare(strict_types=1);
use Domains\CapitalMarkets\Application\Service\PerInstrumentStreamTrustMachine;
use Domains\CapitalMarkets\Application\Service\MultiSymbolStreamingCore;
require dirname(__DIR__,2).'/vendor/autoload.php';
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$machine=new PerInstrumentStreamTrustMachine();
$first=$machine->decide(null,'BBO','BBO','150',true,10);
$assert($first['status']==='TRUSTED'&&$first['sequence']==='150','Fresh complete BBO was blocked.');
$previous=['fencing_token'=>10,'last_sequence'=>'150','status'=>'TRUSTED','recovery_required'=>0];
$next=$machine->decide($previous,'BBO','BBO','151',true,10);
$assert($next['status']==='TRUSTED','Newer independent BBO rejected.');
foreach(['150','149'] as $bad){
    $out=$machine->decide($previous,'BBO','BBO',$bad,true,10);
    $assert($out['status']==='UNTRUSTED'&&$out['recovery_required']===1,
        'Duplicate/rollback in same instrument was trusted.');
}
$blocked=$machine->decide($previous,'BBO','BBO','152',false,10);
$assert($blocked['status']==='UNTRUSTED','Bad quality resurrected trust.');
$recover=['fencing_token'=>10,'last_sequence'=>'150','status'=>'UNTRUSTED','recovery_required'=>1];
$assert($machine->decide($recover,'BBO','BBO','151',true,10)['status']==='TRUSTED',
    'Complete new BBO cannot recover its own instrument.');
$assert($machine->decide($recover,'BBO','BBO','149',true,10)['status']==='UNTRUSTED',
    'Old quarantined sequence re-admitted.');
$assert($machine->decide($previous,'BBO','BBO','1',true,11)['status']==='TRUSTED',
    'New lease epoch failed to reset sequence baseline.');
$assert($machine->decide(null,'L2','ORDER_BOOK_DELTA','100',true,20)['status']==='UNTRUSTED',
    'L2 delta without verified snapshot was accepted.');
$snapshot=$machine->decide(null,'L2','ORDER_BOOK_SNAPSHOT','100',true,20);
$assert($snapshot['status']==='TRUSTED','Full L2 snapshot should recover stream.');
$book=['fencing_token'=>20,'last_sequence'=>'100','status'=>'TRUSTED','recovery_required'=>0];
$assert($machine->decide($book,'L2','ORDER_BOOK_DELTA','101',true,20)['status']==='TRUSTED',
    'Valid contiguous L2 delta was rejected.');
foreach(['99','100','103'] as $bad){
    $assert($machine->decide($book,'L2','ORDER_BOOK_DELTA',$bad,true,20)['status']==='UNTRUSTED',
        'L2 rollback/gap should quarantine only that instrument.');
}
$assert($machine->decide($book,'L2','ORDER_BOOK_DELTA',null,true,20)['status']==='UNTRUSTED',
    'Unsequenced L2 delta passed.');
$assert($machine->decide($book,'L2','ORDER_BOOK_SNAPSHOT','104',true,20)['status']==='TRUSTED',
    'Full verified snapshot should be an independent recovery point.');
$assert($machine->decide($book,'BBO','BBO','NaN',true,20)['status']==='UNTRUSTED',
    'Invalid provider sequence passed.');
foreach([['binance.spot.rest',100],['massive.stocks.v2',25]] as [$provider,$capacity]){
    MultiSymbolStreamingCore::assertBatch($provider,2);
    MultiSymbolStreamingCore::assertBatch($provider,$capacity);
    try{MultiSymbolStreamingCore::assertBatch($provider,$capacity+1);throw new RuntimeException('Batch overflow accepted.');}
    catch(DomainException $expected){}
}
try{MultiSymbolStreamingCore::assertBatch('bybit.spot.v5',3);throw new RuntimeException('Unsupported multi worker accepted.');}
catch(DomainException $expected){}
echo "Shared batch capacity + BBO/L2 per-symbol sequence, quality, recovery and epoch fences passed.\n";
