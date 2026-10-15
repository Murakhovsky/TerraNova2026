<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static function(string $p)use($root):string{
    $contents=file_get_contents($root.'/'.$p);
    if(!is_string($contents)||$contents==='')throw new RuntimeException('Missing Instrument Pool scheduler contract '.$p);
    return $contents;
};
$provider=$read('symfony/src/Scheduler/CosScheduleProvider.php');
$services=$read('symfony/config/services.yaml');
$messenger=$read('symfony/config/packages/messenger.yaml');
$compose=$read('docker-compose.yml');
$handler=$read('symfony/src/Application/CapitalMarkets/Handler/RunInstrumentPoolSnapshotScansHandler.php');
$message=$read('symfony/src/Application/CapitalMarkets/Command/RunInstrumentPoolSnapshotScans.php');
$targets=$read('app/Domains/CapitalMarkets/Application/Service/InstrumentPoolScheduleTargets.php');
foreach([
    'capitalMarketsInstrumentPoolScheduledEnabled','capitalMarketsInstrumentPoolIntervalMinutes',
    "new RunInstrumentPoolSnapshotScans('scheduler')",'RedispatchMessage(',
] as $needle){
    if(!str_contains($provider,$needle))throw new RuntimeException('Pool schedule contract missing '.$needle);
}
foreach(['COS_CM_INSTRUMENT_POOL_SCHEDULER_ENABLED','COS_CM_INSTRUMENT_POOL_SCHEDULER_TARGETS_JSON',
    'COS_CM_INSTRUMENT_POOL_SCHEDULER_ACTOR_ID','RunInstrumentPoolSnapshotScansHandler:',
    "env(COS_CM_INSTRUMENT_POOL_SCHEDULER_ENABLED): '0'",
] as $needle){
    if(!str_contains($services,$needle))throw new RuntimeException('Unsafe pool scheduler configuration '.$needle);
}
if(!str_contains($messenger,"'App\\Application\\CapitalMarkets\\Command\\RunInstrumentPoolSnapshotScans': async")
    ||!str_contains($compose,'COS_CM_INSTRUMENT_POOL_SCHEDULER_ENABLED')
    ||!str_contains($message,'final readonly class RunInstrumentPoolSnapshotScans')){
    throw new RuntimeException('Instrument Pool scheduler queue transport missing.');
}
foreach([
    "getenv('COS_CM_INSTRUMENT_POOL_SCHEDULER_ENABLED')!=='1'",
    'isEnabled($org,\'capital_markets\')','reserveScan(','->record(',
    'InstrumentPoolScheduleTargets::parse','catch(Throwable $error)',
    '->hasCapability(', 'CapitalMarketsCapability::MarketDataManage',
] as $needle){
    if(!str_contains($handler,$needle))throw new RuntimeException('Instrument Pool handler guard missing '.$needle);
}
foreach(['count($targets)>25','Duplicate Instrument Pool schedule target','BINANCE','BYBIT','KRAKEN'] as $needle){
    if(!str_contains($targets,$needle))throw new RuntimeException('Instrument Pool scope validation missing '.$needle);
}
$catalogAudit=$read('app/Domains/CapitalMarkets/Application/Service/InstrumentPoolCatalogAudit.php');
$fullCatalog=$read('app/Domains/CapitalMarkets/Application/Service/CrossVenueCatalogDiscovery.php');
if(!str_contains($catalogAudit,'CrossVenueCatalogDiscovery::loadProviderCatalog')
    || !str_contains($fullCatalog,'public static function loadProviderCatalog')
    || !str_contains($fullCatalog,'nextPageCursor')){
    throw new RuntimeException('Lifecycle audit must use paginated shared catalog reader.');
}
echo "Instrument Pool opt-in scheduler contracts passed.\n";
