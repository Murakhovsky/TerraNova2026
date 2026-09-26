<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.33.0','>='),'Growth manifest must remain V0.33+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.30.0','>='),'Growth schema must remain V0.30+.');
$assert(in_array('growth.signal.polling_incidents',$manifest['contributions']['capabilities']??[],true),'Growth polling incident capability is missing.');

$migration='app/migrations/20260924_000098_growth_v0330_collector_incidents.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.33 migration contribution is missing.');
$sql=$read($migration);
foreach([
    'tn_growth_signal_collector_incidents','open_marker',
    'uq_growth_collector_open_incident',"installed_version='0.33.0'","schema_version='0.30.0'",
] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.33 migration missing: '.$needle);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
$assert(str_contains($ownership,"'tn_growth_signal_collector_incidents'"),'Growth collector incident table ownership is missing.');

$service=$read('app/Domains/Growth/Application/Service/GrowthSignalPollingIncidentService.php');
foreach([
    'GrowthSignalPollingIncidentBoundary','GrowthSignalPollingIncidentRepositoryInterface','TransactionManagerInterface',
    'EventBus','AuditRepositoryInterface','failureThreshold','COLLECTOR_INCIDENT_OPENED','COLLECTOR_INCIDENT_RESOLVED',
    'upsertOpen(','resolveOpen(',
] as $needle){
    $assert(str_contains($service,$needle),'Growth collector incident service missing: '.$needle);
}
foreach(['PDO','tn_growth_signal_collector_incidents','Symfony\\','Phalcon\\'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth collector incident application crossed boundary: '.$forbidden);
}

$repository=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthSignalPollingIncidentRepository.php');
foreach([
    'tn_growth_signal_collector_incidents',"status=\\'open\\'",'FOR UPDATE','ON DUPLICATE KEY UPDATE',
    'activeIncidents(','resolveOpen(',
] as $needle){
    $assert(str_contains($repository,$needle),'Growth collector incident repository missing: '.$needle);
}
$assert(substr_count($repository,'organization_id')>=14,'Growth collector incident persistence must remain tenant-scoped.');

$handler=$read('symfony/src/Application/Growth/Command/RunGrowthSignalPollingCommandHandler.php');
foreach([
    'GrowthSignalPollingIncidentBoundary','recordFailure(','recordRecovery(','failureCount',
] as $needle){
    $assert(str_contains($handler,$needle),'Growth polling handler incident integration missing: '.$needle);
}

$provider=$read('symfony/src/Application/Growth/ReadModel/GrowthSignalPollingStatusProvider.php');
foreach([
    'GrowthSignalPollingIncidentBoundary','activeIncidents(','active_incidents',"'incident'",
] as $needle){
    $assert(str_contains($provider,$needle),'Growth polling status incident projection missing: '.$needle);
}

$template=$read('app/Interfaces/Web/View/growth/collectors.phtml');
foreach([
    'data-growth-polling-incidents','Active collector incidents','Failure count','Opened','Last failure',
] as $needle){
    $assert(str_contains($template,$needle),'Growth collector incident workspace missing: '.$needle);
}

$events=$read('app/Domains/Growth/Automation/Event/GrowthEventType.php');
foreach(['growth.collector.incident_opened','growth.collector.incident_resolved'] as $needle){
    $assert(str_contains($events,$needle),'Growth collector incident event missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach([
    "env(COS_GROWTH_COLLECTOR_INCIDENT_FAILURE_THRESHOLD): '3'",
    'MysqlGrowthSignalPollingIncidentRepository','GrowthSignalPollingIncidentRepositoryInterface',
    'GrowthSignalPollingIncidentService','GrowthSignalPollingIncidentBoundary',
    '$failureThreshold: \'%env(int:COS_GROWTH_COLLECTOR_INCIDENT_FAILURE_THRESHOLD)%\'',
] as $needle){
    $assert(str_contains($services,$needle),'Growth V0.33 DI/config missing: '.$needle);
}

echo "Growth V0.33 Collector Incidents & Recovery architecture: OK\n";
