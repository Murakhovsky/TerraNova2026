<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.30.0','>='),'Growth manifest must remain V0.30+.');
$assert(($manifest['schema_version']??null)==='0.28.0','Growth V0.30 must keep schema version 0.28.0.');
$assert(in_array('growth.signal.scheduled_polling',$manifest['contributions']['capabilities']??[],true),'Growth scheduled polling capability is missing.');

$migration='app/migrations/20260924_000095_growth_v0300_signal_polling_scheduler.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.30 lifecycle migration is missing.');
$sql=$read($migration);
foreach(["installed_version='0.30.0'","installed_version='0.29.0'","schema_version='0.28.0'"] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.30 lifecycle migration missing: '.$needle);
}
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden){
    $assert(!str_contains(strtoupper($sql),$forbidden),'Growth V0.30 migration must remain schema-neutral.');
}

$targetContract=$read('app/Domains/Growth/Application/Contract/GrowthSignalPollingTargetRepositoryInterface.php');
$assert(str_contains($targetContract,'function targets('),'Growth polling target read port is missing.');

$targetRepo=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthSignalPollingTargetRepository.php');
foreach([
    'tn_growth_signal_feeds','tn_growth_json_signal_sources',
    "'rss_atom' AS collector","'credentialed_json' AS collector",'enabled=1',
] as $needle){
    $assert(str_contains($targetRepo,$needle),'Growth polling target repository missing: '.$needle);
}
foreach(['credential_reference','identity_value','url,','facts_json'] as $forbidden){
    $assert(!str_contains($targetRepo,$forbidden),'Growth polling target repository reads unnecessary source data: '.$forbidden);
}

$handler=$read('symfony/src/Application/Growth/Command/RunGrowthSignalPollingCommandHandler.php');
foreach([
    'GrowthSignalPollingTargetRepositoryInterface','GrowthSignalCollectorBoundary','ActiveModuleResolver',
    'isEnabled($organizationId,\'growth\')','runCollector(','scheduled-poll:','CorrelationId::generate()',
    'catch(Throwable $error)','completed_runs','failed_runs','skipped_disabled_organizations',
] as $needle){
    $assert(str_contains($handler,$needle),'Growth polling handler missing: '.$needle);
}
foreach(['PDO','tn_growth_','GrowthSignalFeedRepositoryInterface','GrowthJsonSignalSourceRepositoryInterface'] as $forbidden){
    $assert(!str_contains($handler,$forbidden),'Growth polling handler crossed scheduling/application boundary: '.$forbidden);
}

$scheduler=$read('symfony/src/Scheduler/CosScheduleProvider.php');
foreach([
    'RunGrowthSignalPollingCommand','growthCollectorPollingEnabled','growthCollectorPollingIntervalMinutes',
    'growthCollectorPollingActorId','Growth collector polling requires a positive system actor id',
    "new RedispatchMessage(new RunGrowthSignalPollingCommand('scheduler'), 'async')",
] as $needle){
    $assert(str_contains($scheduler,$needle),'Growth polling scheduler wiring missing: '.$needle);
}

$messenger=$read('symfony/config/packages/messenger.yaml');
$assert(
    str_contains($messenger,"'App\\Application\\Growth\\Command\\RunGrowthSignalPollingCommand': async"),
    'Growth polling command must use async Messenger transport.'
);

$services=$read('symfony/config/services.yaml');
foreach([
    "env(COS_GROWTH_COLLECTOR_SCHEDULER_ENABLED): '0'",
    "env(COS_GROWTH_COLLECTOR_SCHEDULER_INTERVAL_MINUTES): '15'",
    "env(COS_GROWTH_COLLECTOR_SCHEDULER_ACTOR_ID): '0'",
    "env(COS_GROWTH_COLLECTOR_SCHEDULER_ORGANIZATION_LIMIT): '500'",
    "env(COS_GROWTH_COLLECTOR_SCHEDULER_SIGNAL_LIMIT): '100'",
    'MysqlGrowthSignalPollingTargetRepository','GrowthSignalPollingTargetRepositoryInterface',
    'RunGrowthSignalPollingCommandHandler',
] as $needle){
    $assert(str_contains($services,$needle),'Growth polling DI/config missing: '.$needle);
}

echo "Growth V0.30 Scheduled Signal Polling architecture: OK\n";
