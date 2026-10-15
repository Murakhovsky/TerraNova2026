<?php
declare(strict_types=1);
use Domains\CapitalMarkets\Application\Service\InstrumentPoolCatalogAudit;
use Domains\CapitalMarkets\Application\Service\CrossVenueCatalogDiscovery;
require dirname(__DIR__,2).'/vendor/autoload.php';
$assert=static function(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);};
$reject=static function(callable $f):bool{try{$f();return false;}catch(DomainException){return true;}};

$catalog=CrossVenueCatalogDiscovery::catalog('BINANCE',json_encode(['symbols'=>[
    ['symbol'=>'AAPLBUSDT','baseAsset'=>'AAPLB','quoteAsset'=>'USDT','status'=>'TRADING'],
    ['symbol'=>'AAPLXUSDT','baseAsset'=>'AAPLX','quoteAsset'=>'USDT','status'=>'TRADING'],
    ['symbol'=>'BTCUSDT','baseAsset'=>'BTC','quoteAsset'=>'USDT','status'=>'TRADING'],
    ['symbol'=>'DELISTUSDT','baseAsset'=>'DELIST','quoteAsset'=>'USDT','status'=>'BREAK'],
]],JSON_THROW_ON_ERROR));
$mapping=[
    ['venue_symbol'=>'AAPLBUSDT','instrument_id'=>'token:b-stock','mapping_status'=>'ACTIVE'],
    ['venue_symbol'=>'BTCUSDT','instrument_id'=>'token:btc','mapping_status'=>'SUSPENDED'],
    ['venue_symbol'=>'STALEUSDT','instrument_id'=>'token:stale','mapping_status'=>'ACTIVE'],
];
$a=InstrumentPoolCatalogAudit::reconcile($catalog,$mapping,4);
$assert($a['counts']===['MAPPED_ACTIVE_OBSERVED'=>1,'MAPPED_STATUS_REVIEW'=>1,
    'MAPPED_NOT_OBSERVED_REVIEW'=>1,'UNMAPPED_REVIEW'=>1],
    'Catalog reconciliation dropped a mapped, unmatched or inactive symbol.');
$assert($a['truncated']===false&&count($a['rows'])===4,
    'Catalog reconciliation altered bounded cardinality.');
$by=[];foreach($a['rows'] as $row)$by[$row['venue_symbol']]=$row;
$assert($by['AAPLBUSDT']['instrument_id']==='token:b-stock'
    &&$by['AAPLXUSDT']['observation']==='UNMAPPED_REVIEW'
    &&$by['AAPLXUSDT']['instrument_id']===null,
    'AAPLx must never be merged into independently issued AAPLB token.');
$assert($by['STALEUSDT']['observation']==='MAPPED_NOT_OBSERVED_REVIEW'
    &&$by['STALEUSDT']['automatically_mutated']===false,
    'Missing catalog symbols must not automatically delist canonical instruments.');
$small=InstrumentPoolCatalogAudit::reconcile($catalog,$mapping,1);
$assert($small['truncated']===true&&count($small['rows'])===1
    &&$small['counts']===$a['counts'],
    'Limited result must report complete counts and truncation.');
$assert($reject(fn()=>InstrumentPoolCatalogAudit::reconcile(
    $catalog,[...$mapping,$mapping[0]],
)),'Ambiguous duplicate venue mapping was accepted.');
$assert($reject(fn()=>InstrumentPoolCatalogAudit::reconcile(
    ['a'=>['symbol'=>'BTCUSDT','base'=>'BTC','quote'=>'USDT'],
     'b'=>['symbol'=>'BTCUSDT','base'=>'BTC','quote'=>'USDT']],[],
)),'Conflicting duplicated provider symbol was accepted.');
echo "Instrument Pool cross-venue mapping audit and no-auto-delist policy passed.\n";
