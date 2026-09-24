<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(($manifest['version']??null)==='0.32.0','Growth V0.32 manifest version must be 0.32.0.');
$assert(($manifest['schema_version']??null)==='0.29.0','Growth V0.32 schema version must be 0.29.0.');
$assert(in_array('growth.signal.polling_health',$manifest['contributions']['capabilities']??[],true),'Growth polling health capability is missing.');

$migration='app/migrations/20260924_000097_growth_v0320_collector_health_backoff.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.32 migration contribution is missing.');
$sql=$read($migration);
foreach([
    'tn_growth_signal_collector_health',
    "installed_version='0.32.0'",
    "installed_version='0.31.0'",
    "schema_version='0.29.0'",
    "schema_version='0.28.0'",
] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.32 migration missing: '.$needle);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
$assert(str_contains($ownership,"'tn_growth_signal_collector_health'"),'Growth collector health table ownership is missing.');

$policy=$read('app/Domains/Growth/Domain/SignalPollingBackoffPolicy.php');
foreach(['delayMinutes(','nextRetryAt(','2**$exponent','maxMinutes'] as $needle){
    $assert(str_contains($policy,$needle),'Growth adaptive backoff policy missing: '.$needle);
}

$repository=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthSignalPollingHealthRepository.php');
foreach([
    'tn_growth_signal_collector_health','markHealthy(','markDegraded(','markFailed(',
    'consecutive_failures=consecutive_failures+1','next_retry_at=NULL',
] as $needle){
    $assert(str_contains($repository,$needle),'Growth polling health repository missing: '.$needle);
}
$assert(substr_count($repository,'organization_id')>=12,'Growth polling health persistence must remain tenant-scoped.');

$handler=$read('symfony/src/Application/Growth/Command/RunGrowthSignalPollingCommandHandler.php');
foreach([
    'GrowthSignalPollingHealthRepositoryInterface','SignalPollingBackoffPolicy','isCoolingDown(',
    'skipped_backoff_runs','markHealthy(','markDegraded(','markFailed(','next_retry_at',
] as $needle){
    $assert(str_contains($handler,$needle),'Growth polling backoff handler missing: '.$needle);
}
foreach(['PDO','tn_growth_signal_collector_health'] as $forbidden){
    $assert(!str_contains($handler,$forbidden),'Growth polling handler crossed persistence boundary: '.$forbidden);
}

$provider=$read('symfony/src/Application/Growth/ReadModel/GrowthSignalPollingStatusProvider.php');
foreach([
    'GrowthSignalPollingHealthRepositoryInterface','statesForOrganization(','health_status','collector_health',
    'consecutive_failures','next_retry_at',
] as $needle){
    $assert(str_contains($provider,$needle),'Growth polling health status projection missing: '.$needle);
}

$template=$read('app/Interfaces/Web/View/growth/collectors.phtml');
foreach([
    'data-growth-polling-health','Collector health','Consecutive failures','Next retry',
] as $needle){
    $assert(str_contains($template,$needle),'Growth collector health workspace missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach([
    "env(COS_GROWTH_COLLECTOR_MAX_BACKOFF_MINUTES): '360'",
    'MysqlGrowthSignalPollingHealthRepository',
    'GrowthSignalPollingHealthRepositoryInterface',
    'SignalPollingBackoffPolicy:',
    '$health: \'@Domains\\Growth\\Application\\Contract\\GrowthSignalPollingHealthRepositoryInterface\'',
    '$backoff: \'@Domains\\Growth\\Domain\\SignalPollingBackoffPolicy\'',
] as $needle){
    $assert(str_contains($services,$needle),'Growth V0.32 DI/config missing: '.$needle);
}

echo "Growth V0.32 Collector Health & Adaptive Backoff architecture: OK\n";
