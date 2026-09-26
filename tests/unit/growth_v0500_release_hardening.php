<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Growth\Application\DTO\GrowthMarketDiscoveredAccount;
use Domains\Growth\Application\DTO\GrowthMarketDiscoveryBatch;

function expectGrowthV0500(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$item=new GrowthMarketDiscoveredAccount(
    'provider-1','Acme','acme.example',
    ['industry'=>'saas','employee_count'=>80],['hubspot'],['sales'],['funding'],['hiring'],
    ['provider://acme'],new DateTimeImmutable('2026-09-26T12:00:00+00:00')
);
$batch=new GrowthMarketDiscoveryBatch([$item],'cursor-2',1,['One provider item was rejected.']);
expectGrowthV0500($batch->observedCount()===2,'Observed market count must include accepted and rejected provider items.');
expectGrowthV0500($batch->rejectedCount===1,'Rejected provider count drifted.');
expectGrowthV0500($batch->nextCursor==='cursor-2','Market cursor drifted.');

$invalid=false;
try{new GrowthMarketDiscoveryBatch([],null,0,['orphan error']);}catch(InvalidArgumentException){$invalid=true;}
expectGrowthV0500($invalid,'Batch errors without rejected items must be rejected.');

$negative=false;
try{new GrowthMarketDiscoveryBatch([],null,-1,[]);}catch(InvalidArgumentException){$negative=true;}
expectGrowthV0500($negative,'Negative rejected count must be rejected.');

echo "Growth V0.50 Market Discovery hardening contracts passed.\n";
