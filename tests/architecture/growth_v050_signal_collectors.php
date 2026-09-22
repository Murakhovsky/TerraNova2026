<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.5.0','>='),'Growth manifest must remain V0.5+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.5.0','>='),'Growth schema must remain V0.5+.');
$assert(($manifest['enabled_by_default']??true)===false,'Growth V0.5 must remain disabled before delivery cutover.');
$migration='app/migrations/20260922_000070_growth_v050_signal_collectors.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.5 migration contribution is missing.');
foreach(['growth.signal.collect','growth.signal.ingest','growth.signal.dedupe'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'Growth collector capability missing: '.$capability);
}

$migrationSql=$read($migration);
foreach(['tn_growth_signal_collector_runs','tn_growth_signal_source_receipts','external_key_hash',"installed_version='0.5.0'"] as $needle){
    $assert(str_contains($migrationSql,$needle),'Growth collector migration missing: '.$needle);
}
$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(['tn_growth_signal_collector_runs','tn_growth_signal_source_receipts'] as $table){
    $assert(str_contains($ownership,"'".$table."'"),'Growth collector ownership missing: '.$table);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthSignalCollectorService.php');
foreach([
    'GrowthSignalCollectorBoundary','SignalCollectorRegistry','GrowthSignalIntakeRepositoryInterface','GrowthRepositoryInterface',
    'GrowthMutationReceiptInterface','transactions->transactional','collector->collect','claimSource',
    'GrowthEventType::COLLECTOR_RUN_STARTED','GrowthEventType::COLLECTOR_RUN_COMPLETED',
    'GrowthEventType::COLLECTOR_RUN_FAILED','GrowthEventType::SIGNAL_DETECTED','idempotency_key_hash',
] as $needle){
    $assert(str_contains($service,$needle),'Growth collector service missing: '.$needle);
}
$collectPos=strpos($service,'collector->collect');
$firstTransactionPos=strpos($service,'transactions->transactional');
$assert($collectPos!==false&&$firstTransactionPos!==false&&$collectPos>$firstTransactionPos,'Growth collector call should happen after run transaction setup.');
foreach(['PDO','Symfony\\','Phalcon\\','Infrastructure\\','Platform\\'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth collector application crossed its boundary: '.$forbidden);
}

$repository=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthSignalIntakeRepository.php');
foreach(['INSERT IGNORE INTO tn_growth_signal_source_receipts','payload_fingerprint','hash_equals','status=\\'running\\'','organization_id=:organization_id'] as $needle){
    $assert(str_contains($repository,$needle),'Growth collector repository missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'SignalCollectorInterface','growth.signal_collector','SignalCollectorRegistry',
    'GrowthSignalIntakeRepositoryInterface','MysqlGrowthSignalIntakeRepository',
    'GrowthSignalCollectorBoundary','GrowthSignalCollectorService',
] as $needle){
    $assert(str_contains($services,$needle),'Growth collector DI missing: '.$needle);
}

echo "Growth V0.5 Signal Collector architecture: OK\n";
