<?php
declare(strict_types=1);
use Domains\CapitalMarkets\Application\Service\StreamingOperationsHealthEvaluator;
require dirname(__DIR__,2).'/vendor/autoload.php';
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$e=new StreamingOperationsHealthEvaluator();
$empty=$e->evaluate(['leases'=>[],'quality_by_source'=>[]]);
$assert($empty['health']==='NO_EVIDENCE'&&$empty['alerts']===[],'No data cannot equal certified healthy.');
$unsafe=$e->evaluate([
    'leases'=>[
        ['source_id'=>'binance','shard_key'=>'batch','lease_state'=>'EXPIRED'],
        ['source_id'=>'bybit','shard_key'=>'single','lease_state'=>'ACTIVE'],
    ],
    'quality_by_source'=>[
        'binance'=>[
            'trading'=>['TRUSTED'=>4,'DEGRADED'=>2,'STALE'=>1,'UNTRUSTED'=>2,'UNAVAILABLE'=>1],
            'reference'=>['TRUSTED'=>1,'DEGRADED'=>0,'STALE'=>0,'UNTRUSTED'=>0,'UNAVAILABLE'=>0],
        ],
    ],
]);
$assert($unsafe['health']==='CRITICAL'
    &&$unsafe['counts']===['CRITICAL'=>2,'WARNING'=>1]
    &&count($unsafe['alerts'])===3,
    'Expired lease, untrusted market state and degraded data must alert separately.');
$serialized=json_encode($unsafe,JSON_THROW_ON_ERROR);
$assert(!str_contains($serialized,'owner_id')
    &&!str_contains($serialized,'fencing_token'),
    'Health alerts must not expose worker secrets.');
$healthy=$e->evaluate(['leases'=>[
    ['source_id'=>'binance','shard_key'=>'batch','lease_state'=>'ACTIVE'],
], 'quality_by_source'=>[
    'binance'=>['trading'=>['TRUSTED'=>2],'reference'=>['TRUSTED'=>2]],
]]);
$assert($healthy['health']==='OK'&&$healthy['alerts']===[],
    'Healthy trust and active lease incorrectly alerted.');
$bad=false;
try{$e->evaluate(['leases'=>[],'quality_by_source'=>[
    'binance'=>['trading'=>['MAGIC'=>1]],
]]);}catch(DomainException){$bad=true;}
$assert($bad,'Unknown trust state must fail closed.');
echo "Streaming operations alert projection passed.\n";
