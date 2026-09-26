<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.31.0','>='),'Growth manifest must remain V0.31+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.28.0','>='),'Growth schema must remain V0.28+.');
$assert(in_array('growth.signal.polling_workspace',$manifest['contributions']['capabilities']??[],true),'Growth polling workspace capability is missing.');

$migration='app/migrations/20260924_000096_growth_v0310_polling_operations_workspace.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.31 lifecycle migration is missing.');
$sql=$read($migration);
foreach(["installed_version='0.31.0'","installed_version='0.30.0'","schema_version='0.28.0'"] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.31 lifecycle migration missing: '.$needle);
}
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden){
    $assert(!str_contains(strtoupper($sql),$forbidden),'Growth V0.31 migration must remain schema-neutral.');
}

$contract=$read('app/Domains/Growth/Application/Contract/GrowthSignalPollingTargetRepositoryInterface.php');
$assert(str_contains($contract,'function targetForOrganization('),'Growth polling tenant-scoped status contract is missing.');

$repository=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthSignalPollingTargetRepository.php');
foreach([
    'function targetForOrganization(','organization_id=:organization_id','organization_id=:organization_id2',
    'COUNT(*) AS source_count','source_counts',
] as $needle){
    $assert(str_contains($repository,$needle),'Growth polling tenant status repository missing: '.$needle);
}
foreach(['credential_reference','identity_value','facts_json'] as $forbidden){
    $assert(!str_contains($repository,$forbidden),'Growth polling status repository reads sensitive/unnecessary data: '.$forbidden);
}

$provider=$read('symfony/src/Application/Growth/ReadModel/GrowthSignalPollingStatusProvider.php');
foreach([
    'GrowthSignalPollingTargetRepositoryInterface','ActiveModuleResolver','targetForOrganization(',
    'isEnabled($organizationId,\'growth\')','actor_configured','enabled_source_count','scheduler_disabled',
    'system_actor_missing','no_enabled_sources',
] as $needle){
    $assert(str_contains($provider,$needle),'Growth polling status provider missing: '.$needle);
}
foreach(['PDO','credential_reference','identity_value','actor_id','organization_limit'] as $forbidden){
    $assert(!str_contains($provider,$forbidden),'Growth polling status provider exposes/couples forbidden data: '.$forbidden);
}

$controller=$read('symfony/src/Web/Growth/GrowthPageController.php');
foreach([
    'GrowthSignalPollingStatusProvider',"'polling'=>\$this->pollingStatus->status(",'function collectors(',
] as $needle){
    $assert(str_contains($controller,$needle),'Growth V0.31 SSR polling composition missing: '.$needle);
}
foreach(['pollingStatus->set','RunGrowthSignalPollingCommand','dispatch('] as $forbidden){
    $assert(!str_contains($controller,$forbidden),'Growth polling workspace must remain read-only: '.$forbidden);
}

$template=$read('app/Interfaces/Web/View/growth/collectors.phtml');
foreach([
    'data-growth-polling-status','Scheduled Signal Polling','Automatic monitoring',
    'System actor','Enabled sources','Per-run limit','Scheduler configuration is deployment-owned and read-only here.',
] as $needle){
    $assert(str_contains($template,$needle),'Growth V0.31 polling workspace template missing: '.$needle);
}
foreach(['COS_GROWTH_COLLECTOR_SCHEDULER_ACTOR_ID','credential_reference','organization_limit'] as $forbidden){
    $assert(!str_contains($template,$forbidden),'Growth polling workspace exposes forbidden runtime data: '.$forbidden);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'GrowthSignalPollingStatusProvider:',
    '$targets: \'@Domains\\Growth\\Application\\Contract\\GrowthSignalPollingTargetRepositoryInterface\'',
    '$enabled: \'%env(bool:COS_GROWTH_COLLECTOR_SCHEDULER_ENABLED)%\'',
    '$actorId: \'%env(int:COS_GROWTH_COLLECTOR_SCHEDULER_ACTOR_ID)%\'',
] as $needle){
    $assert(str_contains($services,$needle),'Growth polling workspace DI missing: '.$needle);
}

echo "Growth V0.31 Polling Operations Workspace architecture: OK\n";
